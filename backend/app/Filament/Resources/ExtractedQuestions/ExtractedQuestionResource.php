<?php

namespace App\Filament\Resources\ExtractedQuestions;

use App\Filament\Resources\ExtractedQuestions\Pages\ListExtractedQuestions;
use App\Filament\Resources\ExtractedQuestions\Pages\ReviewExtractedQuestion;
use App\Models\Examora\ExtractedQuestion;
use App\Models\Examora\Topic;
use App\Services\Ingestion\DocumentReviewer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;
use UnitEnum;

/**
 * The human gate on the ingestion pipeline.
 *
 * Nothing extracted from a document reaches `past_questions` — and therefore the
 * importance scores, lessons and readiness estimates every student sees —
 * without passing through this queue.
 */
class ExtractedQuestionResource extends Resource
{
    protected static ?string $model = ExtractedQuestion::class;

    protected static BackedEnum|string|null $navigationIcon = 'phosphor-file-magnifying-glass-duotone';

    protected static ?string $navigationLabel = 'Question review';

    protected static UnitEnum|string|null $navigationGroup = 'Examora';

    protected static ?int $navigationSort = 1;

    /** A queue with a badge on it is a queue that gets worked. */
    public static function getNavigationBadge(): ?string
    {
        $pending = static::getModel()::where('status', ExtractedQuestion::STATUS_PENDING)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('stem')
                ->label('Question')
                ->required()
                ->rows(3)
                ->columnSpanFull(),

            Repeater::make('options')
                ->label('Options')
                ->simple(TextInput::make('option')->required())
                ->minItems(2)
                ->maxItems(6)
                ->columnSpanFull(),

            TextInput::make('correct_index')
                ->label('Correct option (0-based)')
                ->numeric()
                ->minValue(0)
                ->maxValue(5)
                ->helperText('Leave blank only if the paper genuinely does not print an answer — you cannot approve without it.'),

            Select::make('topic_id')
                ->label('Topic')
                ->options(fn (?ExtractedQuestion $record) => Topic::query()
                    ->with('subject:id,name')
                    ->whereHas('subject', fn ($q) => $q->where('exam_id', $record?->exam_id))
                    ->get()
                    ->mapWithKeys(fn (Topic $t) => [$t->id => ($t->subject?->name.' — '.$t->name)])
                    ->all())
                ->searchable()
                ->required(),

            TextInput::make('year')->numeric()->minValue(1950)->maxValue(2100)->required(),
            TextInput::make('session_label')->maxLength(191),

            Select::make('difficulty')
                ->options(['easy' => 'Easy', 'medium' => 'Medium', 'hard' => 'Hard'])
                ->default('medium'),

            Textarea::make('explanation')->rows(3)->columnSpanFull(),

            Textarea::make('review_note')
                ->label('Reviewer note')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),

                TextColumn::make('stem')
                    ->label('Question')
                    ->limit(70)
                    ->wrap()
                    ->searchable(),

                TextColumn::make('topic.name')
                    ->label('Topic')
                    ->placeholder('Unclassified')
                    ->badge()
                    ->color(fn ($state) => $state === null ? 'danger' : 'gray'),

                TextColumn::make('confidence')
                    ->label('Transcription')
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : round($state * 100).'%')
                    ->color(fn ($state) => $state !== null && $state < 0.7 ? 'danger' : 'success')
                    ->sortable(),

                IconColumn::make('correct_index')
                    ->label('Key')
                    ->boolean()
                    ->getStateUsing(fn (ExtractedQuestion $r) => $r->correct_index !== null),

                TextColumn::make('document.title')->label('Source')->limit(24),

                TextColumn::make('document.visibility')
                    ->label('Scope')
                    ->badge()
                    ->color(fn ($state) => $state === 'shared' ? 'success' : 'warning')
                    ->tooltip('Private uploads cannot enter the shared question bank.'),

                TextColumn::make('page_number')->label('Page')->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        'duplicate' => 'info',
                        default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        ExtractedQuestion::STATUS_PENDING => 'Pending',
                        ExtractedQuestion::STATUS_DUPLICATE => 'Duplicate',
                        ExtractedQuestion::STATUS_APPROVED => 'Approved',
                        ExtractedQuestion::STATUS_REJECTED => 'Rejected',
                    ])
                    ->default(ExtractedQuestion::STATUS_PENDING),

                TernaryFilter::make('unclassified')
                    ->label('Missing topic')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNull('topic_id'),
                        false: fn (Builder $q) => $q->whereNotNull('topic_id'),
                        blank: fn (Builder $q) => $q,
                    ),

                TernaryFilter::make('low_confidence')
                    ->label('Low confidence')
                    ->queries(
                        true: fn (Builder $q) => $q->where('confidence', '<', 0.7),
                        false: fn (Builder $q) => $q->where('confidence', '>=', 0.7),
                        blank: fn (Builder $q) => $q,
                    ),
            ])
            ->recordActions([
                Action::make('review')
                    ->label('Review')
                    ->url(fn (ExtractedQuestion $record) => ReviewExtractedQuestion::getUrl(['record' => $record])),

                Action::make('reject')
                    ->label('Reject')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ExtractedQuestion $record) => $record->status !== ExtractedQuestion::STATUS_REJECTED)
                    ->action(function (ExtractedQuestion $record) {
                        app(DocumentReviewer::class)->reject($record, auth()->user());

                        Notification::make()->title('Rejected')->success()->send();
                    }),
            ])
            ->headerActions([
                // Bulk approval is deliberately absent. Approving in bulk is
                // approving without reading, which is exactly the failure mode
                // this queue exists to prevent.
            ])
            ->emptyStateHeading('Nothing waiting for review')
            ->emptyStateDescription('Upload a question paper to start the ingestion pipeline.');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['topic', 'document']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExtractedQuestions::route('/'),
            'review' => ReviewExtractedQuestion::route('/{record}/review'),
        ];
    }

    /** Shared by the review page's approve action. */
    public static function approve(ExtractedQuestion $record, array $data): void
    {
        try {
            app(DocumentReviewer::class)->approve($record, auth()->user(), $data);

            Notification::make()
                ->title('Approved and added to the question bank')
                ->body('Run `examora:bootstrap` to fold it into the topic scores.')
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()->title('Could not approve')->body($e->getMessage())->danger()->send();
        }
    }
}
