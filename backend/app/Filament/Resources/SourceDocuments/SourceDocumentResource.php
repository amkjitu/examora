<?php

namespace App\Filament\Resources\SourceDocuments;

use App\Filament\Resources\SourceDocuments\Pages\ListSourceDocuments;
use App\Jobs\ProcessSourceDocument;
use App\Models\Examora\SourceDocument;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class SourceDocumentResource extends Resource
{
    protected static ?string $model = SourceDocument::class;

    protected static BackedEnum|string|null $navigationIcon = 'phosphor-file-pdf-duotone';

    protected static ?string $navigationLabel = 'Source documents';

    protected static UnitEnum|string|null $navigationGroup = 'Examora';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')->searchable()->limit(40),
                TextColumn::make('user.name')->label('Uploaded by')->limit(20),
                TextColumn::make('exam.name')->label('Exam')->limit(20),
                TextColumn::make('doc_type')->badge(),

                TextColumn::make('visibility')
                    ->badge()
                    ->color(fn ($state) => $state === 'shared' ? 'success' : 'warning')
                    ->tooltip('Only shared documents can be promoted into the question bank.'),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'extracted' => 'success',
                        'failed' => 'danger',
                        'processing' => 'info',
                        default => 'gray',
                    }),

                TextColumn::make('pages_processed')
                    ->label('Pages')
                    ->formatStateUsing(fn ($state, SourceDocument $r) => "{$state}/{$r->page_count}"),

                TextColumn::make('extracted_questions_count')->counts('extractedQuestions')->label('Questions'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('visibility')->options([
                    SourceDocument::VISIBILITY_PRIVATE => 'Private',
                    SourceDocument::VISIBILITY_SHARED => 'Shared',
                ]),
                SelectFilter::make('status')->options([
                    SourceDocument::STATUS_PENDING => 'Pending',
                    SourceDocument::STATUS_PROCESSING => 'Processing',
                    SourceDocument::STATUS_EXTRACTED => 'Extracted',
                    SourceDocument::STATUS_FAILED => 'Failed',
                ]),
            ])
            ->recordActions([
                // The quarantine release valve, and the only way across it.
                // Deliberately manual and confirmed: marking a document shared
                // makes its questions eligible for the corpus that drives every
                // student's topic scores.
                Action::make('mark_shared')
                    ->label('Mark as shared')
                    ->color('success')
                    ->icon('phosphor-globe-duotone')
                    ->requiresConfirmation()
                    ->modalDescription(
                        'Questions from a shared document can be approved into the corpus that '
                        .'drives topic importance for every student. Only do this for verified, '
                        .'licensed material.'
                    )
                    ->visible(fn (SourceDocument $r) => ! $r->isShared())
                    ->action(function (SourceDocument $record) {
                        $record->update(['visibility' => SourceDocument::VISIBILITY_SHARED]);

                        Notification::make()->title('Marked as shared')->success()->send();
                    }),

                Action::make('mark_private')
                    ->label('Return to private')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (SourceDocument $r) => $r->isShared())
                    ->action(function (SourceDocument $record) {
                        $record->update(['visibility' => SourceDocument::VISIBILITY_PRIVATE]);

                        Notification::make()->title('Returned to private')->success()->send();
                    }),

                Action::make('reprocess')
                    ->label('Reprocess')
                    ->icon('phosphor-arrow-clockwise-duotone')
                    ->requiresConfirmation()
                    ->modalDescription('Pages already transcribed are skipped, so this only redoes what failed.')
                    ->action(function (SourceDocument $record) {
                        ProcessSourceDocument::dispatch($record->id);

                        Notification::make()->title('Queued for reprocessing')->success()->send();
                    }),
            ])
            ->emptyStateHeading('No documents uploaded yet');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user:id,name', 'exam:id,name']);
    }

    public static function getPages(): array
    {
        return ['index' => ListSourceDocuments::route('/')];
    }
}
