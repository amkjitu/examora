<?php

namespace App\Services\Learning;

use App\Models\Examora\Exam;
use App\Models\Examora\PastQuestion;
use App\Models\Examora\Topic;
use App\Models\Examora\TopicStatistic;
use Illuminate\Support\Facades\DB;

/**
 * The exam-intelligence core: how likely is this topic to be worth marks?
 *
 * Three signals, deliberately simple and explainable — a student must be able to
 * see *why* a topic is five stars, and a content editor must be able to argue
 * with it:
 *
 *   frequency  how often the topic has been examined, per year of coverage
 *   recency    exponentially weighted toward recent sessions, because syllabi drift
 *   weight     the marks the parent subject carries in the exam
 *
 * Scores are normalised within an exam, so "★★★★★" always means "top of this
 * exam" rather than an absolute count that shifts as the corpus grows.
 */
class TopicImportanceScorer
{
    private const RECENCY_DECAY = 0.85;

    private const W_FREQUENCY = 0.55;

    private const W_RECENCY = 0.30;

    private const W_SUBJECT = 0.15;

    /** Minutes of study per distinct examined fact. */
    private const MINUTES_PER_FACT = 1.5;

    public function recomputeExam(Exam $exam): int
    {
        $currentYear = (int) date('Y');

        $rows = PastQuestion::query()
            ->where('exam_id', $exam->id)
            ->whereNotNull('topic_id')
            ->select('topic_id', 'year', DB::raw('count(*) as total'), DB::raw('count(distinct fingerprint) as distinct_facts'))
            ->groupBy('topic_id', 'year')
            ->get()
            ->groupBy('topic_id');

        if ($rows->isEmpty()) {
            return 0;
        }

        $maxSubjectMarks = max(1, (int) $exam->subjects()->max('marks'));
        $subjectMarks = Topic::query()
            ->whereIn('id', $rows->keys())
            ->with('subject:id,marks')
            ->get()
            ->mapWithKeys(fn (Topic $t) => [$t->id => (int) ($t->subject->marks ?? 0)]);

        $raw = [];

        foreach ($rows as $topicId => $yearRows) {
            $years = $yearRows->pluck('total', 'year')->map(fn ($v) => (int) $v)->all();
            $questionCount = array_sum($years);
            $distinctFacts = (int) $yearRows->sum('distinct_facts');
            $firstYear = min(array_keys($years));
            $lastYear = max(array_keys($years));
            $span = max(1, $currentYear - $firstYear + 1);

            $recency = 0.0;
            $recencyMax = 0.0;

            foreach ($years as $year => $count) {
                $decay = self::RECENCY_DECAY ** max(0, $currentYear - (int) $year);
                $recency += $count * $decay;
                $recencyMax += $count;
            }

            $raw[$topicId] = [
                'years' => $years,
                'question_count' => $questionCount,
                'distinct_facts' => max(1, $distinctFacts),
                'first_year' => $firstYear,
                'last_year' => $lastYear,
                'frequency_per_year' => round($questionCount / $span, 3),
                'recency_score' => $recencyMax > 0 ? round($recency / $recencyMax, 4) : 0.0,
                'subject_weight' => ($subjectMarks[$topicId] ?? 0) / $maxSubjectMarks,
            ];
        }

        $maxFrequency = max(array_column($raw, 'frequency_per_year')) ?: 1;
        $scored = [];

        foreach ($raw as $topicId => $data) {
            $score = 100 * (
                self::W_FREQUENCY * ($data['frequency_per_year'] / $maxFrequency)
                + self::W_RECENCY * $data['recency_score']
                + self::W_SUBJECT * $data['subject_weight']
            );

            $scored[$topicId] = ['score' => round($score, 2)] + $data;
        }

        $stars = $this->assignStars(array_map(fn ($d) => $d['score'], $scored));
        $now = now();

        foreach ($scored as $topicId => $data) {
            $expected = $data['frequency_per_year'];

            TopicStatistic::updateOrCreate(
                ['exam_id' => $exam->id, 'topic_id' => $topicId],
                [
                    'question_count' => $data['question_count'],
                    'first_year' => $data['first_year'],
                    'last_year' => $data['last_year'],
                    'years' => $data['years'],
                    'frequency_per_year' => $data['frequency_per_year'],
                    'recency_score' => $data['recency_score'],
                    'importance_score' => $data['score'],
                    'probability_stars' => $stars[$topicId],
                    'expected_questions_min' => (int) floor($expected),
                    'expected_questions_max' => (int) ceil($expected + 0.5),
                    'estimated_minutes' => $this->estimateMinutes($data['distinct_facts']),
                    'computed_at' => $now,
                ]
            );
        }

        return count($scored);
    }

    /**
     * Quintile ranking within the exam. Using quantiles rather than fixed score
     * cut-offs keeps the star distribution stable as the question corpus grows.
     *
     * @param  array<int, float>  $scores
     * @return array<int, int>
     */
    private function assignStars(array $scores): array
    {
        if ($scores === []) {
            return [];
        }

        $sorted = $scores;
        arsort($sorted);

        $total = count($sorted);
        $stars = [];
        $rank = 0;

        foreach ($sorted as $topicId => $score) {
            $percentile = $total === 1 ? 0.0 : $rank / ($total - 1);

            $stars[$topicId] = match (true) {
                $percentile <= 0.10 => 5,
                $percentile <= 0.30 => 4,
                $percentile <= 0.60 => 3,
                $percentile <= 0.85 => 2,
                default => 1,
            };

            $rank++;
        }

        return $stars;
    }

    private function estimateMinutes(int $distinctFacts): int
    {
        return (int) min(15, max(3, ceil($distinctFacts * self::MINUTES_PER_FACT)));
    }
}
