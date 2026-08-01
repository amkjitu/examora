<?php

namespace App\Services\Learning;

use App\Models\Examora\Exam;
use App\Models\Examora\Lesson;
use App\Models\Examora\MemoryItem;
use App\Models\Examora\Question;
use App\Models\Examora\QuizAnswer;
use App\Models\Examora\QuizSession;
use App\Models\Examora\Topic;
use App\Models\Examora\TopicPrediction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class QuizEngine
{
    public function __construct(
        private readonly RevisionScheduler $scheduler,
        private readonly ProgressTracker $progress,
    ) {}

    public function startLessonCheck(User $user, Lesson $lesson, int $count = 5): QuizSession
    {
        $questions = Question::visibleTo($user->id)->active()
            ->where('lesson_id', $lesson->id)
            ->inRandomOrder()
            ->limit($count)
            ->get();

        if ($questions->isEmpty()) {
            $questions = $this->selectForTopic($user, $lesson->topic_id, $count);
        }

        return $this->createSession($user, $lesson->exam_id, $questions, QuizSession::TYPE_LESSON_CHECK, [
            'topic_id' => $lesson->topic_id,
            'lesson_id' => $lesson->id,
        ]);
    }

    public function startPractice(User $user, Topic $topic, int $count = 10): QuizSession
    {
        $questions = $this->selectForTopic($user, $topic->id, $count);

        if ($questions->isEmpty()) {
            throw new RuntimeException('No practice questions available for this topic yet.');
        }

        return $this->createSession($user, $topic->subject->exam_id, $questions, QuizSession::TYPE_PRACTICE, [
            'topic_id' => $topic->id,
        ]);
    }

    public function startRevision(User $user, int $examId, int $count = 15): QuizSession
    {
        $due = $this->scheduler->due($user, $examId, $count);
        $questions = $due->pluck('question')->filter()->values();

        if ($questions->isEmpty()) {
            throw new RuntimeException('Nothing is due for revision right now.');
        }

        return $this->createSession($user, $examId, $questions, QuizSession::TYPE_REVISION);
    }

    /**
     * Mock paper composition mirrors the product spec: mostly questions in the
     * historical pattern, some adjacent-topic variants, some high-probability
     * predictions for topics that are overdue to reappear.
     */
    public function startMock(
        User $user,
        int $examId,
        int $count = 100,
        string $mode = QuizSession::MODE_STRICT,
        ?int $durationMinutes = null,
    ): QuizSession {
        $pattern = (int) round($count * 0.70);
        $similar = (int) round($count * 0.20);
        $predicted = $count - $pattern - $similar;

        $patternQuestions = Question::visibleTo($user->id)->active()
            ->where('exam_id', $examId)
            ->where('origin', Question::ORIGIN_PAST)
            ->inRandomOrder()
            ->limit($pattern)
            ->get();

        $similarQuestions = Question::visibleTo($user->id)->active()
            ->where('exam_id', $examId)
            ->whereNotIn('id', $patternQuestions->pluck('id'))
            ->inRandomOrder()
            ->limit($similar)
            ->get();

        // The prediction slice draws from the topics the forecast actually
        // favours for the next sitting, ordered by probability — not from a
        // random pick of high-star topics, which is what this used to do.
        $forecastTopicIds = TopicPrediction::query()
            ->where('exam_id', $examId)
            ->orderByDesc('appearance_probability')
            ->limit(20)
            ->pluck('topic_id');

        $predictedQuestions = Question::visibleTo($user->id)->active()
            ->where('exam_id', $examId)
            ->whereNotIn('id', $patternQuestions->pluck('id')->merge($similarQuestions->pluck('id')))
            ->when(
                $forecastTopicIds->isNotEmpty(),
                fn ($q) => $q->whereIn('topic_id', $forecastTopicIds),
                // No forecast computed yet — fall back to raw importance so a
                // fresh install still produces a sensible paper.
                fn ($q) => $q->whereHas('topic.statistics', fn ($s) => $s->where('probability_stars', '>=', 4)),
            )
            ->inRandomOrder()
            ->limit($predicted)
            ->get();

        $questions = $patternQuestions->concat($similarQuestions)->concat($predictedQuestions)->shuffle();

        if ($questions->isEmpty()) {
            throw new RuntimeException('Not enough questions in the bank to build a mock exam.');
        }

        $exam = Exam::findOrFail($examId);

        // Duration scales with the paper when the student takes a short mock, so
        // a 25-question practice run keeps the same seconds-per-question
        // pressure as the real 100-question paper.
        $minutes = $durationMinutes
            ?? (int) max(1, round($exam->duration_minutes * ($questions->count() / max(1, $exam->total_marks))));

        // Snapshot readiness now so the post-exam delta is a real before/after
        // rather than a number recomputed from the same data twice.
        $readinessBefore = $this->progress->readiness($user, $examId);

        return $this->createSession($user, $examId, $questions, QuizSession::TYPE_MOCK, [
            'meta' => [
                'mode' => $mode === QuizSession::MODE_PRACTICE
                    ? QuizSession::MODE_PRACTICE
                    : QuizSession::MODE_STRICT,
                'composition' => compact('pattern', 'similar', 'predicted'),
                'duration_minutes' => $minutes,
                'expires_at' => now()->addMinutes($minutes)->toIso8601String(),
                'negative_marking' => (float) $exam->negative_marking,
                'readiness_before' => $readinessBefore['readiness'],
                'estimated_score_before' => $readinessBefore['estimated_score'],
            ],
        ]);
    }

    /**
     * Selection policy for practice: unseen questions first (coverage), then
     * lapsed items (weakness), then everything else. Within each band, questions
     * on higher-probability topics come first.
     *
     * @return Collection<int, Question>
     */
    public function selectForTopic(User $user, ?int $topicId, int $count): Collection
    {
        if ($topicId === null) {
            return collect();
        }

        $seen = MemoryItem::where('user_id', $user->id)
            ->whereHas('question', fn ($q) => $q->where('topic_id', $topicId))
            ->pluck('question_id');

        $unseen = Question::visibleTo($user->id)->active()
            ->where('topic_id', $topicId)
            ->whereNotIn('id', $seen)
            ->inRandomOrder()
            ->limit($count)
            ->get();

        if ($unseen->count() >= $count) {
            return $unseen;
        }

        $lapsed = MemoryItem::where('user_id', $user->id)
            ->where('lapses', '>', 0)
            ->whereHas('question', fn ($q) => $q->where('topic_id', $topicId))
            ->orderByDesc('lapses')
            ->limit($count - $unseen->count())
            ->with('question')
            ->get()
            ->pluck('question')
            ->filter();

        $selection = $unseen->concat($lapsed)->unique('id');

        if ($selection->count() < $count) {
            $filler = Question::visibleTo($user->id)->active()
                ->where('topic_id', $topicId)
                ->whereNotIn('id', $selection->pluck('id'))
                ->inRandomOrder()
                ->limit($count - $selection->count())
                ->get();

            $selection = $selection->concat($filler);
        }

        return $selection->values();
    }

    public function answer(
        QuizSession $session,
        Question $question,
        ?int $selectedIndex,
        ?int $timeMs = null,
        ?int $confidence = null,
    ): array {
        if ($session->isComplete()) {
            throw new RuntimeException('This quiz session is already complete.');
        }

        if (! in_array($question->id, $session->question_ids, true)) {
            throw new RuntimeException('That question is not part of this session.');
        }

        // The clock is the server's, not the client's. A tab left open past the
        // deadline cannot keep submitting.
        if ($session->hasExpired()) {
            throw new RuntimeException('Time is up for this exam. Submit it to see your result.');
        }

        $isCorrect = $selectedIndex !== null && $question->isCorrect($selectedIndex);

        DB::transaction(function () use ($session, $question, $selectedIndex, $isCorrect, $timeMs, $confidence) {
            QuizAnswer::updateOrCreate(
                ['quiz_session_id' => $session->id, 'question_id' => $question->id],
                [
                    'user_id' => $session->user_id,
                    'selected_index' => $selectedIndex,
                    'is_correct' => $isCorrect,
                    'time_ms' => $timeMs,
                    'confidence' => $confidence,
                    'answered_at' => now(),
                ]
            );

            $question->increment('times_served');

            if ($isCorrect) {
                $question->increment('times_correct');
            }

            $question->refresh();
            $question->update([
                'p_value' => $question->times_served > 0
                    ? round($question->times_correct / $question->times_served, 4)
                    : null,
            ]);

            $this->scheduler->record($session->user, $question, $isCorrect, $timeMs, $confidence);
        });

        // Under exam conditions the answer key never leaves the server until the
        // paper is submitted. This is enforced here rather than hidden in the
        // client, because a client-side secret is not a secret.
        if ($session->isStrict()) {
            return [
                'recorded' => true,
                'mode' => QuizSession::MODE_STRICT,
                'seconds_remaining' => $session->secondsRemaining(),
            ];
        }

        return [
            'recorded' => true,
            'mode' => QuizSession::MODE_PRACTICE,
            'is_correct' => $isCorrect,
            'correct_index' => (int) $question->correct_index,
            'explanation' => $question->explanation,
            'previously_examined' => $question->meta['years'] ?? [],
            'next_review_in_days' => MemoryItem::where('user_id', $session->user_id)
                ->where('question_id', $question->id)
                ->value('interval_days'),
            'seconds_remaining' => $session->secondsRemaining(),
        ];
    }

    public function complete(QuizSession $session): QuizSession
    {
        $answers = $session->answers()->get();
        $correct = $answers->where('is_correct', true)->count();
        $attempted = $answers->whereNotNull('selected_index')->count();
        $wrong = max(0, $attempted - $correct);
        $total = max(1, count($session->question_ids));

        // Negative marking is zero for everything except a mock, so this same
        // arithmetic gives the plain percentage elsewhere.
        $penalty = $session->negativeMarking();
        $marks = round($correct - ($wrong * $penalty), 2);

        $session->update([
            'total' => count($session->question_ids),
            'correct' => $correct,
            'score' => round(($marks / $total) * 100, 2),
            'duration_seconds' => $session->started_at ? (int) $session->started_at->diffInSeconds(now()) : null,
            'completed_at' => now(),
            'meta' => array_merge($session->meta ?? [], [
                'attempted' => $attempted,
                'wrong' => $wrong,
                'skipped' => $total - $attempted,
                'marks' => $marks,
                'max_marks' => $total,
                'penalty_marks' => round($wrong * $penalty, 2),
            ]),
        ]);

        $this->progress->afterQuiz($session->fresh(['answers']));

        return $session->fresh(['answers']);
    }

    /**
     * Auto-submit an expired paper. Called when a student returns to a mock whose
     * clock ran out while the tab was closed — they should see their result, not
     * a dead session.
     */
    public function completeIfExpired(QuizSession $session): QuizSession
    {
        if (! $session->isComplete() && $session->hasExpired()) {
            return $this->complete($session);
        }

        return $session;
    }

    private function createSession(User $user, int $examId, Collection $questions, string $type, array $extra = []): QuizSession
    {
        $meta = $extra['meta'] ?? null;
        unset($extra['meta']);

        return QuizSession::create(array_merge([
            'user_id' => $user->id,
            'exam_id' => $examId,
            'type' => $type,
            'question_ids' => $questions->pluck('id')->all(),
            'total' => $questions->count(),
            'started_at' => now(),
            'meta' => $meta,
        ], $extra));
    }
}
