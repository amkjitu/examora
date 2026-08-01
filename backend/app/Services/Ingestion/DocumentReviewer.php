<?php

namespace App\Services\Ingestion;

use App\Models\Examora\ExtractedQuestion;
use App\Models\Examora\PastQuestion;
use App\Models\Examora\Question;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The gate between "the model found this" and "the platform believes this".
 *
 * Promotion is always a deliberate human act, for two reasons:
 *
 *   1. A wrong answer key poisons the topic importance score, the lesson built
 *      from it and the readiness estimate — simultaneously, and silently.
 *   2. Student uploads are quarantined. A private document's questions can power
 *      that student's own practice, but they never enter the shared corpus that
 *      moves every other student's numbers.
 */
class DocumentReviewer
{
    public function approve(ExtractedQuestion $extracted, User $reviewer, array $corrections = []): PastQuestion
    {
        if (! $extracted->isReviewable() && $extracted->status !== ExtractedQuestion::STATUS_DUPLICATE) {
            throw new RuntimeException('This question has already been reviewed.');
        }

        $document = $extracted->document;

        if (! $document->canEnterSharedCorpus()) {
            throw new RuntimeException(
                'This question came from a private upload and cannot enter the shared question bank. '
                .'Mark the source document as shared first, which is an admin action.'
            );
        }

        // A reviewer may fix the transcription or the key on the way through;
        // that correction is the whole point of the queue.
        $stem = trim($corrections['stem'] ?? $extracted->stem);
        $options = array_values($corrections['options'] ?? $extracted->options);
        $correctIndex = $corrections['correct_index'] ?? $extracted->correct_index;
        $topicId = $corrections['topic_id'] ?? $extracted->topic_id;
        $year = $corrections['year'] ?? $extracted->year;

        if ($correctIndex === null) {
            throw new RuntimeException('Set the correct answer before approving this question.');
        }

        if ($correctIndex < 0 || $correctIndex >= count($options)) {
            throw new RuntimeException('The correct answer must be one of the options.');
        }

        if ($topicId === null) {
            throw new RuntimeException('Assign a topic before approving this question.');
        }

        if ($year === null) {
            throw new RuntimeException('Set the exam year before approving this question.');
        }

        return DB::transaction(function () use (
            $extracted,
            $reviewer,
            $document,
            $stem,
            $options,
            $correctIndex,
            $topicId,
            $year,
            $corrections
        ) {
            $fingerprint = PastQuestion::fingerprintFor($stem, $options);

            // updateOrCreate on (exam, fingerprint, year) means approving the
            // same question twice is idempotent, and the same question from a
            // different year correctly becomes a second occurrence.
            $past = PastQuestion::updateOrCreate(
                [
                    'exam_id' => $document->exam_id,
                    'fingerprint' => $fingerprint,
                    'year' => $year,
                ],
                [
                    'topic_id' => $topicId,
                    'session_label' => $corrections['session_label'] ?? $extracted->session_label,
                    'stem' => $stem,
                    'options' => $options,
                    'correct_index' => $correctIndex,
                    'explanation' => $corrections['explanation'] ?? $extracted->explanation,
                    'difficulty' => $corrections['difficulty'] ?? $extracted->difficulty,
                    'marks' => 1,
                    'source' => "document:{$document->id}",
                    'meta' => [
                        'source_document_id' => $document->id,
                        'page_number' => $extracted->page_number,
                        'reviewed_by' => $reviewer->id,
                    ],
                ]
            );

            $extracted->update([
                'status' => ExtractedQuestion::STATUS_APPROVED,
                'past_question_id' => $past->id,
                'topic_id' => $topicId,
                'correct_index' => $correctIndex,
                'stem' => $stem,
                'options' => $options,
                'year' => $year,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $corrections['review_note'] ?? null,
            ]);

            return $past;
        });
    }

    public function reject(ExtractedQuestion $extracted, User $reviewer, ?string $note = null): ExtractedQuestion
    {
        $extracted->update([
            'status' => ExtractedQuestion::STATUS_REJECTED,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);

        return $extracted;
    }

    /**
     * Questions from a private upload, made available to their owner alone.
     *
     * They land directly in the practice bank rather than in past_questions, so
     * the student can drill their own material while the exam intelligence every
     * other student depends on stays untouched.
     */
    public function publishPrivately(ExtractedQuestion $extracted): ?Question
    {
        $document = $extracted->document;

        if ($document->isShared() || $extracted->correct_index === null) {
            return null;
        }

        // Scoped by owner_id, not by a `meta` marker. The marker version leaked
        // into every other student's practice pool because nothing enforced it.
        // Matching on owner too means two students who upload the same paper each
        // get their own copy rather than fighting over one row.
        return Question::withAllOwners()->updateOrCreate(
            [
                'fingerprint' => $extracted->fingerprint,
                'exam_id' => $document->exam_id,
                'owner_id' => $document->user_id,
            ],
            [
                'topic_id' => $extracted->topic_id,
                'origin' => Question::ORIGIN_CURATED,
                'stem' => $extracted->stem,
                'options' => $extracted->options,
                'correct_index' => $extracted->correct_index,
                'explanation' => $extracted->explanation,
                'difficulty' => $extracted->difficulty,
                'is_active' => true,
                'meta' => ['source_document_id' => $document->id],
            ]
        );
    }
}
