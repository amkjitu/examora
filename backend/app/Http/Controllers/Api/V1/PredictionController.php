<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Examora\Exam;
use App\Models\Examora\TopicPrediction;
use App\Services\Prediction\PredictionEngine;
use App\Services\Prediction\RepeatPredictor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PredictionController extends Controller
{
    public function __construct(
        private readonly PredictionEngine $engine,
        private readonly RepeatPredictor $repeats,
    ) {}

    public function index(Request $request, Exam $exam): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $limit = $validated['limit'] ?? 25;

        $predictions = $this->engine->topFor($exam, $limit);

        if ($predictions->isEmpty()) {
            return response()->json([
                'data' => null,
                'message' => 'No forecast yet. Run `php artisan examora:bootstrap` once the question bank is populated.',
            ]);
        }

        $mastery = $request->user()
            ? $request->user()->topicMastery()->where('exam_id', $exam->id)->get()->keyBy('topic_id')
            : collect();

        return response()->json(['data' => [
            'exam' => ['id' => $exam->id, 'name' => $exam->name, 'slug' => $exam->slug],
            'target_year' => $predictions->first()->target_year,
            'model_version' => $predictions->first()->model_version,
            'computed_at' => $predictions->first()->computed_at?->toIso8601String(),

            // Sent with every response so no client can render a forecast without
            // the caveat attached to it.
            'disclaimer' => 'A forecast of what past papers suggest, not a syllabus. '
                .'Never drop a topic because it ranks low here.',

            // If the question bank stops years before the sitting the student is
            // preparing for, the forecast is about a session that has already
            // happened. Say so.
            'freshness' => $this->engine->freshness($exam),

            'topics' => $predictions->map(fn (TopicPrediction $p) => [
                'rank' => $p->rank,
                'topic' => [
                    'id' => $p->topic?->id,
                    'name' => $p->topic?->name,
                    'slug' => $p->topic?->slug,
                    'subject' => $p->topic?->subject?->name,
                ],
                'appearance_probability' => $p->appearance_probability,
                'expected_questions' => $p->expected_questions,
                'expected_range' => [$p->expected_low, $p->expected_high],
                'confidence' => $p->confidence,
                'confidence_label' => $p->confidenceLabel(),
                'trend' => $p->trend,
                'is_overdue' => $p->isOverdue(),
                'evidence' => $p->evidence ?? [],
                'signals' => $p->signals ?? [],
                'mastery' => round((float) ($mastery[$p->topic_id]->mastery ?? 0), 2),
            ]),

            'repeat_candidates' => $this->repeats->candidates($exam, 20)->map(fn (array $c) => [
                'past_question_id' => $c['past_question_id'],
                'stem' => $c['stem'],
                'answer' => $c['answer'],
                'explanation' => $c['explanation'],
                'topic' => $c['topic'],
                'years' => $c['years'],
                'appearances' => $c['appearances'],
                'last_seen' => $c['last_seen'],
                'mean_gap_years' => $c['mean_gap_years'],
                'score' => $c['score'],
                'reworded' => $c['reworded'],
                'evidence' => $c['evidence'],
            ]),
        ]]);
    }

    /**
     * Honest self-assessment: hold out the most recent session, forecast it from
     * everything before, and report how close the model got.
     *
     * Exposed rather than buried in a test because the answer changes as the
     * question bank grows, and anyone relying on these numbers deserves to be
     * able to check them.
     */
    public function backtest(Exam $exam): JsonResponse
    {
        return response()->json(['data' => $this->engine->backtest($exam) + [
            'note' => 'Brier score is the mean squared error of the appearance probability. '
                .'Lower is better; 0.25 is what always guessing 50% would score.',
        ]]);
    }
}
