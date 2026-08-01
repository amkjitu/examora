<?php

namespace App\Services\Knowledge;

use App\Models\Examora\KnowledgeChunk;
use App\Services\Ai\AiGateway;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Retrieval over the knowledge graph.
 *
 * Uses pgvector's cosine operator when the connection supports it and falls back
 * to an in-PHP cosine scan otherwise, so tests and CI need no vector database.
 * The fallback is scoped by exam/topic first, which keeps the candidate set in
 * the hundreds rather than the corpus size.
 */
class Retriever
{
    public function __construct(private readonly AiGateway $ai) {}

    /**
     * @return Collection<int, KnowledgeChunk>
     */
    public function search(string $query, int $examId, ?int $topicId = null, int $limit = 12): Collection
    {
        $vector = $this->ai->embedOne($query);

        if ($vector === []) {
            return $this->lexicalFallback($query, $examId, $topicId, $limit);
        }

        return KnowledgeChunk::usesPgVector()
            ? $this->searchPgVector($vector, $examId, $topicId, $limit)
            : $this->searchInMemory($vector, $examId, $topicId, $limit);
    }

    private function searchPgVector(array $vector, int $examId, ?int $topicId, int $limit): Collection
    {
        $literal = '['.implode(',', array_map(fn ($v) => (float) $v, $vector)).']';

        $query = KnowledgeChunk::query()
            ->where('exam_id', $examId)
            ->whereNotNull('embedding')
            ->select('*')
            ->selectRaw('1 - (embedding <=> ?::vector) as similarity', [$literal])
            ->orderByRaw('embedding <=> ?::vector', [$literal])
            ->limit($limit);

        if ($topicId !== null) {
            $query->where('topic_id', $topicId);
        }

        return $query->get();
    }

    private function searchInMemory(array $vector, int $examId, ?int $topicId, int $limit): Collection
    {
        $candidates = KnowledgeChunk::query()
            ->where('exam_id', $examId)
            ->when($topicId !== null, fn ($q) => $q->where('topic_id', $topicId))
            ->where('source_type', '!=', Indexer::SOURCE_PAST_QUESTION_IDENTITY)
            ->whereNotNull('embedding')
            ->limit(2000)
            ->get();

        return $candidates
            ->map(function (KnowledgeChunk $chunk) use ($vector) {
                $chunk->similarity = self::cosine($vector, $chunk->vector() ?? []);

                return $chunk;
            })
            ->sortByDesc('similarity')
            ->take($limit)
            ->values();
    }

    private function lexicalFallback(string $query, int $examId, ?int $topicId, int $limit): Collection
    {
        $terms = collect(preg_split('/\s+/', trim($query)) ?: [])
            ->filter(fn ($t) => mb_strlen($t) > 3)
            ->take(6);

        return KnowledgeChunk::query()
            ->where('exam_id', $examId)
            ->when($topicId !== null, fn ($q) => $q->where('topic_id', $topicId))
            ->when($terms->isNotEmpty(), function ($q) use ($terms) {
                $q->where(function ($inner) use ($terms) {
                    foreach ($terms as $term) {
                        $inner->orWhere('content', 'like', '%'.$term.'%');
                    }
                });
            })
            ->limit($limit)
            ->get();
    }

    public static function cosine(array $a, array $b): float
    {
        $length = min(count($a), count($b));

        if ($length === 0) {
            return 0.0;
        }

        $dot = $normA = $normB = 0.0;

        for ($i = 0; $i < $length; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        if ($normA <= 0 || $normB <= 0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /** Convenience for callers that only need raw text for a prompt. */
    public function contextFor(string $query, int $examId, ?int $topicId = null, int $limit = 12): string
    {
        return $this->search($query, $examId, $topicId, $limit)
            ->map(fn (KnowledgeChunk $c) => trim(($c->title ? $c->title.': ' : '').$c->content))
            ->implode("\n---\n");
    }

    public static function supportsVectorSearch(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
}
