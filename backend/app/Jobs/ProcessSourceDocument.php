<?php

namespace App\Jobs;

use App\Models\Examora\SourceDocument;
use App\Services\Ingestion\DocumentReviewer;
use App\Services\Ingestion\PageExtractor;
use App\Services\Ingestion\QuestionExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ingestion runs on the queue because it is bursty, slow and latency-tolerant —
 * nobody is watching a spinner while a 200-page paper is transcribed.
 *
 * Both stages are resumable: pages that already have text are skipped, and
 * questions already staged from this document are not staged twice. A retry
 * after a provider outage therefore costs only the work that actually failed.
 */
class ProcessSourceDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 1800;

    public array $backoff = [30, 120];

    public function __construct(public readonly int $documentId) {}

    public function handle(
        PageExtractor $pages,
        QuestionExtractor $questions,
        DocumentReviewer $reviewer,
    ): void {
        $document = SourceDocument::with('exam')->find($this->documentId);

        if (! $document) {
            return;
        }

        $document->update([
            'status' => SourceDocument::STATUS_PROCESSING,
            'error' => null,
        ]);

        $extractedPages = $pages->extract($document);
        $staged = $questions->extract($document);

        // A private upload is the student's own material, so it goes straight
        // into their personal practice bank — there is nothing for a reviewer to
        // certify, because it never reaches the shared corpus. Anything without
        // a printed answer key is skipped rather than guessed at.
        $published = 0;

        if (! $document->isShared()) {
            foreach ($document->extractedQuestions()->whereNotNull('correct_index')->get() as $extracted) {
                if ($reviewer->publishPrivately($extracted)) {
                    $published++;
                }
            }
        }

        $document->update([
            'status' => SourceDocument::STATUS_EXTRACTED,
            'processed_at' => now(),
            'meta' => array_merge($document->meta ?? [], [
                'pages_with_text' => $extractedPages,
                'questions_staged' => $staged,
                'questions_published_privately' => $published,
            ]),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Document ingestion failed', [
            'document_id' => $this->documentId,
            'error' => $exception->getMessage(),
        ]);

        SourceDocument::where('id', $this->documentId)->update([
            'status' => SourceDocument::STATUS_FAILED,
            'error' => $exception->getMessage(),
        ]);
    }
}
