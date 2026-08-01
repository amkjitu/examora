<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Examora\Exam;
use App\Models\Examora\ExamEnrollment;
use App\Services\Learning\DailyPlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExamController extends Controller
{
    public function __construct(private readonly DailyPlanner $planner) {}

    public function index(): JsonResponse
    {
        $exams = Exam::where('is_active', true)
            ->withCount('pastQuestions')
            ->with('subjects:id,exam_id,name,slug,marks,position')
            ->get()
            ->map(fn (Exam $exam) => [
                'id' => $exam->id,
                'code' => $exam->code,
                'name' => $exam->name,
                'slug' => $exam->slug,
                'authority' => $exam->authority,
                'description' => $exam->description,
                'total_marks' => $exam->total_marks,
                'duration_minutes' => $exam->duration_minutes,
                'negative_marking' => (float) $exam->negative_marking,
                'past_questions_count' => $exam->past_questions_count,
                'subjects' => $exam->subjects->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'slug' => $s->slug,
                    'marks' => $s->marks,
                ]),
            ]);

        return response()->json(['data' => $exams]);
    }

    public function show(Exam $exam): JsonResponse
    {
        return response()->json(['data' => [
            'id' => $exam->id,
            'code' => $exam->code,
            'name' => $exam->name,
            'slug' => $exam->slug,
            'authority' => $exam->authority,
            'description' => $exam->description,
            'total_marks' => $exam->total_marks,
            // The mock setup screen needs both of these to show the student what
            // they are signing up for before they start the clock.
            'duration_minutes' => $exam->duration_minutes,
            'negative_marking' => (float) $exam->negative_marking,
            'subjects' => $exam->subjects->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'slug' => $s->slug,
                'marks' => $s->marks,
            ]),
        ]]);
    }

    /** Chapters for an exam, ordered by how likely they are to be worth marks. */
    public function topics(Request $request, Exam $exam): JsonResponse
    {
        $validated = $request->validate([
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $topics = $this->planner->topicsWithProbability(
            $exam->id,
            $validated['subject_id'] ?? null,
            $validated['limit'] ?? 50,
        );

        $mastery = $request->user()
            ? $request->user()->topicMastery()->where('exam_id', $exam->id)->get()->keyBy('topic_id')
            : collect();

        return response()->json(['data' => $topics->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'slug' => $t->slug,
            'description' => $t->description,
            'subject' => ['name' => $t->subject_name, 'slug' => $t->subject_slug],
            'question_count' => (int) $t->question_count,
            'probability_stars' => (int) $t->probability_stars,
            'importance_score' => round((float) $t->importance_score, 1),
            'estimated_minutes' => (int) $t->estimated_minutes,
            'expected_questions' => $t->expected_questions_min !== null
                ? "{$t->expected_questions_min}-{$t->expected_questions_max}"
                : null,
            'last_examined' => $t->last_year,
            'mastery' => round((float) ($mastery[$t->id]->mastery ?? 0), 2),
            'lesson_completed' => (bool) ($mastery[$t->id]->lesson_completed ?? false),
            // Null until a forecast has been computed, so clients must handle
            // its absence rather than assume it.
            'forecast' => $t->appearance_probability === null ? null : [
                'appearance_probability' => round((float) $t->appearance_probability, 4),
                'confidence' => round((float) $t->prediction_confidence, 4),
                'trend' => $t->trend,
                'is_overdue' => (float) $t->overdue_ratio >= 1.2,
            ],
        ])]);
    }

    public function enroll(Request $request, Exam $exam): JsonResponse
    {
        $validated = $request->validate([
            'target_session' => ['nullable', 'string', 'max:64'],
            'target_date' => ['nullable', 'date', 'after:today'],
            'daily_minutes_goal' => ['nullable', 'integer', 'min:5', 'max:240'],
        ]);

        $request->user()->enrollments()->where('exam_id', '!=', $exam->id)->update(['is_active' => false]);

        $enrollment = ExamEnrollment::updateOrCreate(
            ['user_id' => $request->user()->id, 'exam_id' => $exam->id],
            [
                'target_session' => $validated['target_session'] ?? null,
                'target_date' => $validated['target_date'] ?? null,
                'daily_minutes_goal' => $validated['daily_minutes_goal'] ?? config('examora.daily_minutes'),
                'is_active' => true,
                'started_at' => now(),
            ]
        );

        return response()->json(['data' => [
            'id' => $enrollment->id,
            'exam' => ['id' => $exam->id, 'name' => $exam->name, 'slug' => $exam->slug],
            'target_session' => $enrollment->target_session,
            'target_date' => $enrollment->target_date?->toDateString(),
            'days_left' => $enrollment->daysLeft(),
            'daily_minutes_goal' => $enrollment->daily_minutes_goal,
        ]], 201);
    }
}
