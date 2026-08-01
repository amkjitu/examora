<?php

namespace App\Services\Prediction;

use App\Models\Examora\Exam;
use App\Models\Examora\PastQuestion;
use App\Models\Examora\TopicPrediction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Forecasts what the next sitting will ask.
 *
 * This is the most dangerous thing in the product. A student who trusts a
 * forecast and drops a topic on the strength of it can lose real marks, so
 * everything here is built to be explainable and to admit what it does not know:
 *
 *   - the model is arithmetic, not a black box, and every number it produces is
 *     stored alongside the signals that produced it;
 *   - confidence falls out of sample size and cycle regularity, so a topic seen
 *     once in twelve years is never presented like one seen every other year;
 *   - the expected range widens as confidence drops, rather than reporting a
 *     single falsely precise figure.
 *
 * ## The model
 *
 * Expected question count for the next session is a Poisson mean, blended from
 * two rates:
 *
 *     λ = (1 − w) · base_rate + w · recent_rate
 *
 * `base_rate` is questions per session across the whole observed history;
 * `recent_rate` is the same over the last few sessions only. Recent behaviour is
 * weighted more heavily because syllabi drift.
 *
 * That λ is then adjusted for **overdue-ness**:
 *
 *     λ' = λ · (1 + α · (overdue_ratio − 1))
 *
 * where `overdue_ratio` is years since the topic last appeared divided by its own
 * mean gap between appearances.
 *
 * This adjustment encodes a real assumption, and it is worth stating plainly: a
 * memoryless Poisson process would say being "overdue" changes nothing. Exam
 * boards are not memoryless — they rotate through a syllabus, so a topic absent
 * for longer than its usual cycle is genuinely more likely to return. That makes
 * this a renewal process, not a Poisson one. The adjustment is damped (α = 0.35)
 * because the rotation is loose, not a schedule.
 *
 * Probability of at least one question is then the Poisson complement:
 *
 *     P(≥1) = 1 − e^(−λ')
 */
class PredictionEngine
{
    public const MODEL_VERSION = 'v1';

    /** Weight given to recent sessions over the full history. */
    private const RECENCY_WEIGHT = 0.6;

    /** How many recent sessions count as "recent". */
    private const RECENT_WINDOW = 5;

    /** Damping on the overdue adjustment. Rotation is loose, not a schedule. */
    private const OVERDUE_ALPHA = 0.35;

    private const OVERDUE_MIN = 0.5;

    private const OVERDUE_MAX = 2.0;

    /** Appearances needed before the sample stops dominating confidence. */
    private const SAMPLE_HALF_POINT = 3;

    /** Relative change over the recent window before we call it a trend. */
    private const TREND_THRESHOLD = 0.25;

    /**
     * @return int number of topics forecast
     */
    public function forecast(Exam $exam, ?int $targetYear = null): int
    {
        $rows = PastQuestion::query()
            ->where('exam_id', $exam->id)
            ->whereNotNull('topic_id')
            ->select('topic_id', 'year', DB::raw('count(*) as total'))
            ->groupBy('topic_id', 'year')
            ->get()
            ->groupBy('topic_id');

        if ($rows->isEmpty()) {
            return 0;
        }

        // Sessions actually observed in this corpus. Using these rather than
        // calendar years means a gap in the archive does not read as a gap in
        // the exam's behaviour.
        $sessionYears = $rows->flatten(1)->pluck('year')->unique()->sort()->values();
        $lastSession = (int) $sessionYears->last();

        // Forecast the session immediately after the last one we have data for —
        // never the current calendar year by default.
        //
        // Skipping ahead would silently treat every unarchived session as one in
        // which nothing was asked, which makes every topic look overdue and
        // inflates the whole forecast. If the archive is behind, the honest move
        // is to forecast what we can and say the archive is behind.
        $targetYear ??= $lastSession + 1;

        $predictions = [];

        foreach ($rows as $topicId => $yearRows) {
            $predictions[(int) $topicId] = $this->predictTopic(
                $yearRows->pluck('total', 'year')->map(fn ($v) => (int) $v)->all(),
                $sessionYears->all(),
                $targetYear,
            );
        }

        // Rank by probability, then by expected volume — a topic that is likely
        // to appear once ranks below one likely to appear three times.
        uasort($predictions, fn ($a, $b) => [$b['appearance_probability'], $b['expected_questions']]
            <=> [$a['appearance_probability'], $a['expected_questions']]);

        // Predictions for any other target year are superseded. Leaving them
        // would let two forecasts for the same topic surface side by side, and
        // would grow the table without bound as the archive advances.
        TopicPrediction::where('exam_id', $exam->id)
            ->where('target_year', '!=', $targetYear)
            ->delete();

        $now = now();
        $rank = 1;

        foreach ($predictions as $topicId => $prediction) {
            TopicPrediction::updateOrCreate(
                ['exam_id' => $exam->id, 'topic_id' => $topicId, 'target_year' => $targetYear],
                $prediction + [
                    'rank' => min(255, $rank++),
                    'model_version' => self::MODEL_VERSION,
                    'computed_at' => $now,
                ]
            );
        }

        return count($predictions);
    }

    /**
     * @param  array<int, int>  $countsByYear  year => questions asked
     * @param  array<int, int>  $sessionYears  every year the corpus covers
     */
    public function predictTopic(array $countsByYear, array $sessionYears, int $targetYear): array
    {
        ksort($countsByYear);

        $appearanceYears = array_keys($countsByYear);
        $totalQuestions = array_sum($countsByYear);
        $lastSeen = (int) max($appearanceYears);
        $yearsSinceLast = max(0, $targetYear - $lastSeen);

        $observedSessions = max(1, count($sessionYears));
        $recentYears = array_slice($sessionYears, -self::RECENT_WINDOW);

        $baseRate = $totalQuestions / $observedSessions;

        $recentQuestions = 0;
        foreach ($recentYears as $year) {
            $recentQuestions += $countsByYear[$year] ?? 0;
        }
        $recentRate = $recentQuestions / max(1, count($recentYears));

        $lambda = (1 - self::RECENCY_WEIGHT) * $baseRate + self::RECENCY_WEIGHT * $recentRate;

        $gaps = $this->gapsBetween($appearanceYears);
        $meanGap = $gaps === [] ? max(1.0, $observedSessions / 2) : array_sum($gaps) / count($gaps);

        $overdueRatio = $meanGap > 0 ? $yearsSinceLast / $meanGap : 1.0;
        $clampedOverdue = max(self::OVERDUE_MIN, min(self::OVERDUE_MAX, $overdueRatio));

        $adjusted = max(0.0, $lambda * (1 + self::OVERDUE_ALPHA * ($clampedOverdue - 1)));

        $confidence = $this->confidence(count($appearanceYears), $gaps, $observedSessions);

        // Poisson standard deviation is √λ. The interval is widened as confidence
        // falls, so thin evidence visibly produces a vaguer answer instead of a
        // precise-looking one.
        $spread = sqrt(max($adjusted, 0.25)) * (1 + (1 - $confidence));

        return [
            'appearance_probability' => round(1 - exp(-$adjusted), 4),
            'expected_questions' => round($adjusted, 3),
            'expected_low' => round(max(0, $adjusted - $spread), 3),
            'expected_high' => round($adjusted + $spread, 3),
            'confidence' => round($confidence, 4),
            'trend' => $this->trend($baseRate, $recentRate),
            'overdue_ratio' => round($overdueRatio, 3),
            'signals' => [
                'total_questions' => $totalQuestions,
                'appearances' => count($appearanceYears),
                'observed_sessions' => $observedSessions,
                'base_rate' => round($baseRate, 3),
                'recent_rate' => round($recentRate, 3),
                'mean_gap_years' => round($meanGap, 2),
                'years_since_last' => $yearsSinceLast,
                'last_seen' => $lastSeen,
                'years' => $countsByYear,
            ],
            'evidence' => $this->evidence($countsByYear, $lastSeen, $yearsSinceLast, $meanGap, $overdueRatio, $baseRate, $recentRate, $confidence),
        ];
    }

    /** @return array<int, int> */
    private function gapsBetween(array $years): array
    {
        sort($years);
        $gaps = [];

        for ($i = 1; $i < count($years); $i++) {
            $gaps[] = $years[$i] - $years[$i - 1];
        }

        return $gaps;
    }

    /**
     * Confidence blends two things a student would intuitively weigh:
     *
     *   sample     how many times we have actually seen this topic
     *   regularity how consistent the gaps between appearances are
     *
     * A topic asked in 2018, 2021 and 2024 is a pattern. A topic asked once in
     * 2013 is an anecdote, and must not be presented as if it were a pattern.
     */
    private function confidence(int $appearances, array $gaps, int $observedSessions): float
    {
        $sample = $appearances / ($appearances + self::SAMPLE_HALF_POINT);

        // A short archive caps what any topic can claim, but a long one does not
        // hand out free confidence — hence a multiplier rather than a third
        // additive term. Corpus length is a ceiling on certainty, not evidence
        // about this particular topic.
        $coverage = min(1.0, $observedSessions / 8);

        if (count($gaps) < 2) {
            // Nothing to judge regularity from, so lean on sample size alone and
            // cap the result: one or two sightings can never be "high confidence".
            return round(min(0.6, $sample) * $coverage, 4);
        }

        $mean = array_sum($gaps) / count($gaps);
        $variance = array_sum(array_map(fn ($g) => ($g - $mean) ** 2, $gaps)) / count($gaps);
        $coefficientOfVariation = $mean > 0 ? sqrt($variance) / $mean : 1.0;

        $regularity = 1 / (1 + $coefficientOfVariation);

        return round((0.55 * $sample + 0.45 * $regularity) * $coverage, 4);
    }

    private function trend(float $baseRate, float $recentRate): string
    {
        if ($baseRate <= 0) {
            return TopicPrediction::TREND_STEADY;
        }

        $change = ($recentRate - $baseRate) / $baseRate;

        return match (true) {
            $change > self::TREND_THRESHOLD => TopicPrediction::TREND_RISING,
            $change < -self::TREND_THRESHOLD => TopicPrediction::TREND_FALLING,
            default => TopicPrediction::TREND_STEADY,
        };
    }

    /**
     * The sentences the UI shows. A forecast a student cannot interrogate is a
     * forecast they should not act on, so every prediction carries its reasoning.
     *
     * @return array<int, string>
     */
    private function evidence(
        array $countsByYear,
        int $lastSeen,
        int $yearsSinceLast,
        float $meanGap,
        float $overdueRatio,
        float $baseRate,
        float $recentRate,
        float $confidence,
    ): array {
        $years = array_keys($countsByYear);
        $shown = array_slice($years, -6);
        $evidence = [];

        $evidence[] = count($years) === 1
            ? "Asked once, in {$lastSeen}."
            : 'Asked in '.implode(', ', $shown).(count($years) > 6 ? ' and earlier.' : '.');

        if (count($years) >= 2) {
            $evidence[] = 'Comes round roughly every '.$this->plural(round($meanGap, 1), 'year').'.';
        }

        if ($yearsSinceLast > 0) {
            $evidence[] = $overdueRatio >= 1.2
                ? 'Last seen '.$this->plural($yearsSinceLast, 'year').' ago — overdue against its own cycle.'
                : 'Last seen '.$this->plural($yearsSinceLast, 'year').' ago.';
        }

        if ($baseRate > 0) {
            $change = ($recentRate - $baseRate) / $baseRate;

            if ($change > self::TREND_THRESHOLD) {
                $evidence[] = 'Appearing more often in recent sessions than historically.';
            } elseif ($change < -self::TREND_THRESHOLD) {
                $evidence[] = 'Appearing less often lately than it used to.';
            }
        }

        if ($confidence < 0.45) {
            $evidence[] = 'Thin evidence — treat this as a hint, not a plan.';
        }

        return $evidence;
    }

    private function plural(float $count, string $noun): string
    {
        $formatted = fmod($count, 1.0) === 0.0 ? (string) (int) $count : (string) $count;

        return $formatted.' '.$noun.((float) $count === 1.0 ? '' : 's');
    }

    /**
     * Holds out the most recent session and forecasts it from everything before,
     * then compares against what that session actually asked.
     *
     * This is how you find out whether the model is worth showing a student. It
     * is deliberately part of the engine rather than a test fixture, so it can be
     * re-run against a real corpus whenever the question bank grows.
     */
    public function backtest(Exam $exam): array
    {
        $rows = PastQuestion::query()
            ->where('exam_id', $exam->id)
            ->whereNotNull('topic_id')
            ->select('topic_id', 'year', DB::raw('count(*) as total'))
            ->groupBy('topic_id', 'year')
            ->get();

        $allYears = $rows->pluck('year')->unique()->sort()->values();

        if ($allYears->count() < 3) {
            return ['ok' => false, 'reason' => 'Need at least three sessions of history to backtest.'];
        }

        $heldOut = (int) $allYears->last();
        $priorYears = $allYears->slice(0, -1)->all();

        $byTopic = $rows->groupBy('topic_id');
        $errors = [];
        $brier = [];

        foreach ($byTopic as $topicId => $topicRows) {
            $counts = $topicRows->pluck('total', 'year')->map(fn ($v) => (int) $v)->all();
            $history = array_filter($counts, fn ($count, $year) => $year < $heldOut, ARRAY_FILTER_USE_BOTH);

            if ($history === []) {
                continue;
            }

            $prediction = $this->predictTopic($history, $priorYears, $heldOut);

            $actual = $counts[$heldOut] ?? 0;
            $appeared = $actual > 0 ? 1 : 0;

            $errors[] = abs($prediction['expected_questions'] - $actual);
            // Brier score: mean squared error of the probability. Lower is better;
            // 0.25 is what you get by always guessing 50%.
            $brier[] = ($prediction['appearance_probability'] - $appeared) ** 2;
        }

        if ($errors === []) {
            return ['ok' => false, 'reason' => 'No topic had enough history to forecast.'];
        }

        return [
            'ok' => true,
            'held_out_session' => $heldOut,
            'topics_scored' => count($errors),
            'mean_absolute_error' => round(array_sum($errors) / count($errors), 3),
            'brier_score' => round(array_sum($brier) / count($brier), 4),
            'baseline_brier' => 0.25,
        ];
    }

    /** @return Collection<int, TopicPrediction> */
    public function topFor(Exam $exam, int $limit = 25): Collection
    {
        return TopicPrediction::query()
            ->where('exam_id', $exam->id)
            ->orderByDesc('appearance_probability')
            ->orderByDesc('expected_questions')
            ->with('topic.subject:id,name')
            ->limit($limit)
            ->get();
    }

    /**
     * Whether the question bank is current enough for the forecast to be about
     * the exam the student is actually sitting.
     *
     * A forecast built from an archive that stops three years ago is a forecast
     * of a sitting that has already happened. Surfaced rather than swallowed,
     * because it is the difference between a useful tool and a misleading one.
     */
    public function freshness(Exam $exam): array
    {
        $lastSession = (int) PastQuestion::where('exam_id', $exam->id)->max('year');
        $target = (int) TopicPrediction::where('exam_id', $exam->id)->max('target_year');
        $currentYear = (int) date('Y');

        return [
            'last_session_in_bank' => $lastSession ?: null,
            'forecasting' => $target ?: null,
            'sessions_behind' => $lastSession ? max(0, $currentYear - $lastSession - 1) : null,
            'is_current' => $lastSession ? ($currentYear - $lastSession) <= 1 : false,
        ];
    }
}
