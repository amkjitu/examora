<?php

namespace App\Services\Prediction;

use App\Models\Examora\Exam;
use App\Models\Examora\PastQuestion;
use Illuminate\Support\Collection;

/**
 * Which individual questions are most likely to be asked again, verbatim.
 *
 * In papers like BCS Preliminary, examiners reuse questions outright across
 * sessions. That makes a repeat candidate the single highest-value thing a
 * candidate can memorise — far more valuable than another chapter summary — and
 * it is a signal no general-purpose study tool has, because it requires the
 * fingerprinted past-question corpus.
 *
 * A question qualifies only if it has *already* repeated. One appearance is not
 * evidence of anything; two or more is a pattern with a measurable cycle.
 */
class RepeatPredictor
{
    /** Below this many appearances there is no repeat history to reason from. */
    private const MIN_APPEARANCES = 2;

    public function __construct(private readonly NearDuplicateDetector $nearDuplicates) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function candidates(Exam $exam, int $limit = 25, ?int $targetYear = null): Collection
    {
        $questions = PastQuestion::query()
            ->where('exam_id', $exam->id)
            ->whereNotNull('fingerprint')
            ->with('topic.subject:id,name')
            ->get();

        if ($questions->isEmpty()) {
            return collect();
        }

        // Same rule as PredictionEngine: the session after the last one in the
        // bank, never the current calendar year. The two must agree, or a
        // question's "years since last" would differ between the two panels of
        // the same screen.
        $targetYear ??= (int) $questions->max('year') + 1;

        // An examiner who changes three words produces a new fingerprint. Group
        // by near-duplicate cluster where one exists, so a reworded repeat still
        // reads as a return instead of a first appearance.
        $nearDuplicate = $this->nearDuplicates->repeatEvidence($exam);

        return $questions
            ->groupBy(fn (PastQuestion $q) => isset($nearDuplicate[$q->id])
                ? 'cluster:'.implode('-', $nearDuplicate[$q->id]['years'])
                : $q->fingerprint)
            ->filter(fn (Collection $group) => $group->pluck('year')->unique()->count() >= self::MIN_APPEARANCES)
            ->map(fn (Collection $group) => $this->score($group, $targetYear, $nearDuplicate))
            ->sortByDesc('score')
            ->take($limit)
            ->values();
    }

    private function score(Collection $group, int $targetYear, array $nearDuplicate = []): array
    {
        /** @var PastQuestion $latest */
        $latest = $group->sortByDesc('year')->first();

        // True when this candidate was assembled from rewordings rather than
        // exact repeats — worth telling the student, since the wording will
        // likely differ again next time.
        $isReworded = $group->pluck('fingerprint')->unique()->count() > 1;

        $years = $group->pluck('year')->unique()->sort()->values()->all();
        $appearances = count($years);
        $lastSeen = (int) end($years);
        $yearsSince = max(0, $targetYear - $lastSeen);

        $gaps = [];
        for ($i = 1; $i < $appearances; $i++) {
            $gaps[] = $years[$i] - $years[$i - 1];
        }

        $meanGap = array_sum($gaps) / max(1, count($gaps));
        $overdue = $meanGap > 0 ? $yearsSince / $meanGap : 0.0;

        // Three components, each capped so no single one can carry a weak
        // candidate to the top of the list:
        //
        //   repetition  how many times it has already come back
        //   cycle       how close it is to its own usual return interval
        //   regularity  how consistent that interval has been
        $repetition = min(1.0, ($appearances - 1) / 3);
        $cycle = min(1.0, $overdue);

        $regularity = 1.0;
        if (count($gaps) >= 2) {
            $mean = array_sum($gaps) / count($gaps);
            $variance = array_sum(array_map(fn ($g) => ($g - $mean) ** 2, $gaps)) / count($gaps);
            $regularity = $mean > 0 ? 1 / (1 + sqrt($variance) / $mean) : 1.0;
        }

        $score = 0.45 * $repetition + 0.35 * $cycle + 0.20 * $regularity;

        return [
            'past_question_id' => $latest->id,
            'stem' => $latest->stem,
            'options' => array_values($latest->options ?? []),
            'correct_index' => (int) $latest->correct_index,
            'answer' => $latest->correctOption(),
            'explanation' => $latest->explanation,
            'topic' => $latest->topic ? [
                'id' => $latest->topic->id,
                'name' => $latest->topic->name,
                'subject' => $latest->topic->subject?->name,
            ] : null,
            'years' => $years,
            'appearances' => $appearances,
            'last_seen' => $lastSeen,
            'years_since_last' => $yearsSince,
            'mean_gap_years' => round($meanGap, 2),
            'overdue_ratio' => round($overdue, 3),
            'score' => round($score, 4),
            'reworded' => $isReworded,
            'evidence' => $this->evidence($years, $meanGap, $yearsSince, $overdue, $isReworded),
        ];
    }

    /** @return array<int, string> */
    private function evidence(array $years, float $meanGap, int $yearsSince, float $overdue, bool $isReworded = false): array
    {
        $evidence = [
            $isReworded
                // Say which it is. "Verbatim" is a promise about the wording, and
                // a student memorising an exact string deserves to know when the
                // wording has drifted before.
                ? 'Asked in '.implode(', ', $years).', reworded between sittings.'
                : 'Asked verbatim in '.implode(', ', $years).'.',
            'Returns roughly every '.$this->plural(round($meanGap, 1), 'year').'.',
        ];

        if ($overdue >= 1.0) {
            $evidence[] = 'Due again — '.$this->plural($yearsSince, 'year').' since its last appearance.';
        } elseif ($yearsSince > 0) {
            $evidence[] = $this->plural($yearsSince, 'year').' since its last appearance.';
        }

        return $evidence;
    }

    private function plural(float $count, string $noun): string
    {
        $formatted = fmod($count, 1.0) === 0.0 ? (string) (int) $count : (string) $count;

        return $formatted.' '.$noun.((float) $count === 1.0 ? '' : 's');
    }
}
