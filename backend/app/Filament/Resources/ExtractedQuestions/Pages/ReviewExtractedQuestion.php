<?php

namespace App\Filament\Resources\ExtractedQuestions\Pages;

use App\Filament\Resources\ExtractedQuestions\ExtractedQuestionResource;
use App\Models\Examora\ExtractedQuestion;
use App\Services\Ingestion\DocumentReviewer;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/**
 * Review one extracted question: correct it if needed, then approve or reject.
 *
 * Approval is a separate, explicit action rather than a save, because saving a
 * correction and asserting the question is exam-accurate are different claims.
 */
class ReviewExtractedQuestion extends EditRecord
{
    protected static string $resource = ExtractedQuestionResource::class;

    public function getTitle(): string
    {
        return 'Review question';
    }

    public function getSubheading(): ?string
    {
        /** @var ExtractedQuestion $record */
        $record = $this->record;

        $parts = array_filter([
            $record->document?->title,
            $record->page_number ? "page {$record->page_number}" : null,
            $record->confidence !== null ? round($record->confidence * 100).'% transcription confidence' : null,
        ]);

        if (! $record->document?->canEnterSharedCorpus()) {
            $parts[] = 'PRIVATE UPLOAD — cannot enter the shared question bank';
        }

        return implode(' · ', $parts);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label('Approve and publish')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(
                    'This adds the question to the shared bank, where it will move topic '
                    .'importance and every student\'s readiness estimate. Check the answer key first.'
                )
                ->visible(fn () => $this->record->document?->canEnterSharedCorpus())
                ->action(function () {
                    // Save any corrections first, then approve from the saved state.
                    $this->save(shouldRedirect: false);

                    ExtractedQuestionResource::approve($this->record->refresh(), []);

                    $this->redirect(ExtractedQuestionResource::getUrl('index'));
                }),

            Action::make('reject')
                ->label('Reject')
                ->color('danger')
                ->requiresConfirmation()
                ->action(function () {
                    app(DocumentReviewer::class)->reject($this->record, auth()->user());

                    Notification::make()->title('Rejected')->success()->send();

                    $this->redirect(ExtractedQuestionResource::getUrl('index'));
                }),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return ExtractedQuestionResource::getUrl('index');
    }
}
