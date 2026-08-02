<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Examora\Lesson;
use App\Models\Examora\Question;
use App\Models\Examora\QuizSession;
use App\Models\Examora\Topic;
use App\Services\Learning\MockAnalyser;
use App\Services\Learning\QuizEngine;
use App\Support\Entitlements;
use App\Support\Feature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class QuizController extends Controller
{
    public function __construct(
        private readonly QuizEngine $engine,
        private readonly MockAnalyser $analyser,
    ) {}

    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:lesson_check,practice,revision,mock'],
            'lesson_id' => ['required_if:type,lesson_check', 'nullable', 'integer', 'exists:lessons,id'],
            'topic_id' => ['required_if:type,practice', 'nullable', 'integer', 'exists:topics,id'],
            'exam_id' => ['required_if:type,revision', 'required_if:type,mock', 'nullable', 'integer', 'exists:exams,id'],
            'count' => ['nullable', 'integer', 'min:1', 'max:200'],
            'mode' => ['nullable', 'in:strict,practice'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:360'],
        ]);

        $user = $request->user();
        $count = $validated['count'] ?? null;

        // Gated here rather than on the route, because one endpoint serves four
        // quiz types and only mock exams are premium. Practice and revision stay
        // open on every plan — walling revision would cost a student marks, and
        // they carry no marginal serving cost anyway.
        if ($validated['type'] === QuizSession::TYPE_MOCK) {
            $entitlements = Entitlements::for($user);

            if (! $entitlements->allows(Feature::MOCK_EXAM)) {
                return response()->json([
                    'message' => 'Mock exams are not included in your '.$entitlements->label().' plan.',
                    'code' => 'feature_not_included',
                    'feature' => Feature::MOCK_EXAM,
                    'feature_label' => Feature::label(Feature::MOCK_EXAM),
                    'current_tier' => $entitlements->tierKey(),
                    'upgrade_url' => config('examora.upgrade_url'),
                ], 402);
            }
        }

        try {
            $session = match ($validated['type']) {
                'lesson_check' => $this->engine->startLessonCheck(
                    $user,
                    Lesson::findOrFail($validated['lesson_id']),
                    $count ?? config('examora.quiz.lesson_check_questions'),
                ),
                'practice' => $this->engine->startPractice(
                    $user,
                    Topic::with('subject')->findOrFail($validated['topic_id']),
                    $count ?? config('examora.quiz.practice_questions'),
                ),
                'revision' => $this->engine->startRevision(
                    $user,
                    (int) $validated['exam_id'],
                    $count ?? config('examora.quiz.revision_questions'),
                ),
                'mock' => $this->engine->startMock(
                    $user,
                    (int) $validated['exam_id'],
                    $count ?? config('examora.quiz.mock_questions'),
                    $validated['mode'] ?? QuizSession::MODE_STRICT,
                    $validated['duration_minutes'] ?? null,
                ),
            };
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->presentSession($session)], 201);
    }

    public function show(Request $request, QuizSession $session): JsonResponse
    {
        $this->authorizeSession($request, $session);

        // Returning to a mock whose clock ran out while the tab was closed
        // submits it rather than leaving a dead session the student is stuck in.
        $session = $this->engine->completeIfExpired($session);

        return response()->json(['data' => $this->presentSession($session)]);
    }

    public function analysis(Request $request, QuizSession $session): JsonResponse
    {
        $this->authorizeSession($request, $session);

        $session = $this->engine->completeIfExpired($session);

        if (! $session->isComplete()) {
            return response()->json([
                'message' => 'Submit the exam before viewing the analysis.',
                'code' => 'session_incomplete',
            ], 422);
        }

        return response()->json(['data' => $this->analyser->analyse($session)]);
    }

    public function answer(Request $request, QuizSession $session): JsonResponse
    {
        $this->authorizeSession($request, $session);

        $validated = $request->validate([
            'question_id' => ['required', 'integer', 'exists:questions,id'],
            'selected_index' => ['nullable', 'integer', 'min:0', 'max:9'],
            'time_ms' => ['nullable', 'integer', 'min:0'],
            'confidence' => ['nullable', 'integer', 'min:1', 'max:4'],
        ]);

        try {
            $result = $this->engine->answer(
                $session,
                Question::findOrFail($validated['question_id']),
                $validated['selected_index'] ?? null,
                $validated['time_ms'] ?? null,
                $validated['confidence'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $result]);
    }

    public function complete(Request $request, QuizSession $session): JsonResponse
    {
        $this->authorizeSession($request, $session);

        $session = $this->engine->complete($session);
        $profile = $request->user()->profileFor($session->exam_id);

        $meta = $session->meta ?? [];

        return response()->json(['data' => [
            'id' => $session->id,
            'type' => $session->type,
            'total' => $session->total,
            'correct' => $session->correct,
            'wrong' => $meta['wrong'] ?? null,
            'skipped' => $meta['skipped'] ?? null,
            'marks' => $meta['marks'] ?? null,
            'max_marks' => $meta['max_marks'] ?? null,
            'penalty_marks' => $meta['penalty_marks'] ?? null,
            'score' => $session->score,
            'duration_seconds' => $session->duration_seconds,
            'xp' => $profile->xp,
            'level' => $profile->level,
            'streak_days' => $profile->streak_days,
            'level_progress' => $profile->xpIntoLevel(),
            'review' => $session->answers->map(fn ($answer) => [
                'question_id' => $answer->question_id,
                'is_correct' => $answer->is_correct,
                'selected_index' => $answer->selected_index,
            ]),
        ]]);
    }

    private function presentSession(QuizSession $session): array
    {
        $questions = Question::whereIn('id', $session->question_ids)->get()->keyBy('id');
        $answered = $session->answers()->pluck('selected_index', 'question_id');

        // Preserve the order the engine chose rather than the database's.
        $ordered = collect($session->question_ids)
            ->map(fn ($id) => $questions[$id] ?? null)
            ->filter()
            ->values();

        $meta = $session->meta ?? [];

        return [
            'id' => $session->id,
            'type' => $session->type,
            'mode' => $session->mode(),
            'exam_id' => $session->exam_id,
            'topic_id' => $session->topic_id,
            'lesson_id' => $session->lesson_id,
            'total' => $ordered->count(),
            'completed_at' => $session->completed_at?->toIso8601String(),
            // The server owns the clock. The client renders this and counts down
            // locally, but every resume re-reads it from here.
            'seconds_remaining' => $session->secondsRemaining(),
            'duration_minutes' => $meta['duration_minutes'] ?? null,
            'negative_marking' => $meta['negative_marking'] ?? 0,
            'questions' => $ordered->map(fn (Question $q) => $q->toStudentArray() + [
                'answered' => $answered->has($q->id),
                'selected_index' => $answered[$q->id] ?? null,
            ]),
        ];
    }

    private function authorizeSession(Request $request, QuizSession $session): void
    {
        abort_unless($session->user_id === $request->user()->id, 403, 'This quiz session belongs to another user.');
    }
}
