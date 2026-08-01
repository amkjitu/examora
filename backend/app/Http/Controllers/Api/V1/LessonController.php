<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Examora\Lesson;
use App\Models\Examora\Topic;
use App\Services\Learning\LessonGenerator;
use App\Services\Learning\ProgressTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LessonController extends Controller
{
    public function __construct(
        private readonly LessonGenerator $generator,
        private readonly ProgressTracker $progress,
    ) {}

    /**
     * Fetch or generate the lesson for a chapter. Generation is idempotent and
     * cached, so the first student to open a chapter pays the latency and every
     * subsequent one gets it instantly.
     */
    public function show(Request $request, Topic $topic): JsonResponse
    {
        $topic->load('subject.exam', 'statistics');

        $lesson = $this->generator->generate($topic, $request->input('locale', 'en'));

        return response()->json(['data' => $this->present($lesson, $topic)]);
    }

    public function complete(Request $request, Lesson $lesson): JsonResponse
    {
        $this->progress->afterLesson($request->user(), $lesson);
        $profile = $request->user()->profileFor($lesson->exam_id);

        return response()->json(['data' => [
            'lesson_id' => $lesson->id,
            'xp' => $profile->xp,
            'level' => $profile->level,
            'streak_days' => $profile->streak_days,
            'level_progress' => $profile->xpIntoLevel(),
        ]]);
    }

    private function present(Lesson $lesson, Topic $topic): array
    {
        $stats = $topic->statistics;

        return [
            'id' => $lesson->id,
            'title' => $lesson->title,
            'summary' => $lesson->summary,
            'estimated_minutes' => $lesson->estimated_minutes,
            'key_points' => $lesson->key_points ?? [],
            'generated_by' => $lesson->generated_by,
            'topic' => [
                'id' => $topic->id,
                'name' => $topic->name,
                'slug' => $topic->slug,
                'subject' => $topic->subject->name,
                'exam' => $topic->subject->exam->name,
            ],
            'exam_intelligence' => $stats ? [
                'questions_asked_previously' => $stats->question_count,
                'probability_stars' => $stats->probability_stars,
                'expected_questions' => "{$stats->expected_questions_min}-{$stats->expected_questions_max}",
                'years' => $stats->years,
                'last_examined' => $stats->last_year,
            ] : null,
            'cards' => $lesson->cards->map(fn ($card) => [
                'id' => $card->id,
                'type' => $card->type,
                'title' => $card->title,
                'body' => $card->body,
            ]),
        ];
    }
}
