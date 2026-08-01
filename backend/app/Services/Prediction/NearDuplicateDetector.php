<?php

namespace App\Services\Prediction;

use App\Models\Examora\Exam;
use App\Models\Examora\KnowledgeChunk;
use App\Models\Examora\PastQuestion;
use App\Services\Knowledge\Indexer;
use App\Services\Knowledge\Retriever;
use Illuminate\Support\Collection;

/**
 * Finds questions that are the same question, reworded.
 *
 * The fingerprint in `past_questions` is exact-match: it normalises case,
 * punctuation and option order, and nothing else. That is deliberately strict,
 * because a fingerprint collision merges two questions into one fact and would
 * corrupt the importance scores. But it means an examiner who changes three
 * words between sessions produces what looks like a brand-new question, and the
 * repeat predictor goes blind to exactly the pattern it exists to find.
 *
 * This closes that gap using the embeddings already computed for every past
 * question during indexing.
 *
 * ## What it deliberately does NOT do
 *
 * It never writes to `past_questions` and never merges anything. Clusters inform
 * the repeat forecast and appear in an admin review list. A false merge would
 * silently unify two questions with different answer keys, and nobody would
 * notice — so a human confirms every merge or none happens at all.
 */
class NearDuplicateDetector
{
    /**
     * Cosine similarity above which two questions are treated as the same
     * question reworded.
     *
     * Set high on purpose. Questions from one topic are all lexically similar —
     * they share names, dates and vocabulary — so a permissive threshold merges
     * genuinely different questions about the same subject. The cost of a false
     * positive (two answer keys unified) is far higher than a false negative
     * (a repeat missed, which is the status quo).
     */
    public const THRESHOLD = 0.93;

    /** Below this, no cluster is reported at all. */
    private const MIN_CLUSTER_SIZE = 2;

    /**
     * Groups of past questions that appear to be the same question across
     * sessions, excluding those the exact fingerprint already catches.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function clusters(Exam $exam, float $threshold = self::THRESHOLD): Collection
    {
        // Identity chunks, not retrieval chunks. The retrieval chunk carries the
        // session label and year — exactly the part that differs between two
        // appearances of the same question, which buries the signal. See
        // Indexer::SOURCE_PAST_QUESTION_IDENTITY.
        $chunks = KnowledgeChunk::query()
            ->where('exam_id', $exam->id)
            ->where('source_type', Indexer::SOURCE_PAST_QUESTION_IDENTITY)
            ->whereNotNull('embedding')
            ->get();

        if ($chunks->count() < 2) {
            return collect();
        }

        $questions = PastQuestion::whereIn('id', $chunks->pluck('source_id'))
            ->with('topic:id,name')
            ->get()
            ->keyBy('id');

        $vectors = [];
        foreach ($chunks as $chunk) {
            $vector = $chunk->vector();

            if ($vector !== null && isset($questions[$chunk->source_id])) {
                $vectors[$chunk->source_id] = $vector;
            }
        }

        $ids = array_keys($vectors);
        $clusters = $this->cluster($ids, $vectors, $questions, $threshold);

        return collect($clusters)
            ->filter(fn (array $group) => count($group) >= self::MIN_CLUSTER_SIZE)
            ->map(fn (array $group) => $this->describe($group, $questions, $vectors))
            // Only report clusters the exact fingerprint does not already catch —
            // otherwise this just restates what RepeatPredictor already knows.
            ->filter(fn (array $cluster) => $cluster['distinct_fingerprints'] > 1)
            ->sortByDesc('mean_similarity')
            ->values();
    }

    /**
     * Single-link agglomeration by union-find.
     *
     * Single-link chains — A close to B, B close to C pulls A and C together
     * even if they are not close. At a threshold this high that is acceptable and
     * matches how rewordings actually drift across sessions, one edit at a time.
     *
     * @return array<int, array<int, int>>
     */
    private function cluster(array $ids, array $vectors, Collection $questions, float $threshold): array
    {
        $parent = array_combine($ids, $ids);

        $find = function (int $id) use (&$parent, &$find): int {
            while ($parent[$id] !== $id) {
                $parent[$id] = $parent[$parent[$id]];
                $id = $parent[$id];
            }

            return $id;
        };

        $count = count($ids);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $ids[$i];
                $b = $ids[$j];

                // Two questions on different topics being near-identical is far
                // more likely to be an embedding artefact than a reworded repeat.
                if ($questions[$a]->topic_id !== $questions[$b]->topic_id) {
                    continue;
                }

                if (Retriever::cosine($vectors[$a], $vectors[$b]) >= $threshold) {
                    $rootA = $find($a);
                    $rootB = $find($b);

                    if ($rootA !== $rootB) {
                        $parent[$rootB] = $rootA;
                    }
                }
            }
        }

        $groups = [];
        foreach ($ids as $id) {
            $groups[$find($id)][] = $id;
        }

        return array_values($groups);
    }

    private function describe(array $group, Collection $questions, array $vectors): array
    {
        $members = collect($group)->map(fn ($id) => $questions[$id]);
        $years = $members->pluck('year')->unique()->sort()->values()->all();

        $similarities = [];
        for ($i = 0; $i < count($group); $i++) {
            for ($j = $i + 1; $j < count($group); $j++) {
                $similarities[] = Retriever::cosine($vectors[$group[$i]], $vectors[$group[$j]]);
            }
        }

        /** @var PastQuestion $canonical */
        $canonical = $members->sortByDesc('year')->first();

        // Differing answers across a cluster is the loudest possible signal that
        // this is NOT one question reworded, so it is surfaced rather than buried.
        $answers = $members->map(fn (PastQuestion $q) => $q->correctOption())->unique()->values();

        return [
            'canonical_id' => $canonical->id,
            'canonical_stem' => $canonical->stem,
            'topic' => $canonical->topic ? ['id' => $canonical->topic->id, 'name' => $canonical->topic->name] : null,
            'members' => $members->map(fn (PastQuestion $q) => [
                'id' => $q->id,
                'year' => $q->year,
                'stem' => $q->stem,
                'answer' => $q->correctOption(),
                'fingerprint' => $q->fingerprint,
            ])->sortBy('year')->values()->all(),
            'years' => $years,
            'distinct_years' => count($years),
            'distinct_fingerprints' => $members->pluck('fingerprint')->unique()->count(),
            'mean_similarity' => $similarities === [] ? 1.0 : round(array_sum($similarities) / count($similarities), 4),
            'answers_agree' => $answers->count() === 1,
            'conflicting_answers' => $answers->count() > 1 ? $answers->all() : null,
        ];
    }

    /**
     * Repeat evidence keyed by past-question id: how many *distinct sessions* a
     * question has appeared in once rewordings are counted as the same question.
     *
     * RepeatPredictor merges this with its exact-fingerprint view, so a question
     * reworded between sittings still reads as a return.
     *
     * @return array<int, array{years: array<int, int>, near_duplicate: bool}>
     */
    public function repeatEvidence(Exam $exam, float $threshold = self::THRESHOLD): array
    {
        $evidence = [];

        foreach ($this->clusters($exam, $threshold) as $cluster) {
            // A cluster whose members disagree on the answer is not one question.
            // Leave it for a human rather than feeding a guess into the forecast.
            if (! $cluster['answers_agree']) {
                continue;
            }

            foreach ($cluster['members'] as $member) {
                $evidence[$member['id']] = [
                    'years' => $cluster['years'],
                    'near_duplicate' => true,
                    'cluster_size' => count($cluster['members']),
                ];
            }
        }

        return $evidence;
    }
}
