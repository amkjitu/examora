<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Data\LlmRequest;
use App\Services\Ai\Data\LlmResponse;

/**
 * Deterministic offline provider.
 *
 * This is not a stub that returns lorem ipsum. Generators embed a machine
 * readable context block in every prompt (see PromptContext), and the mock
 * builds its answer out of that real, database-sourced context. The result is
 * that the entire product — lessons, quizzes, revision, dashboard — runs and is
 * testable with no API key and no spend, while still exercising the same code
 * path a real provider takes.
 */
class MockProvider extends AbstractProvider
{
    public const CONTEXT_OPEN = '<<<EXAMORA_CONTEXT';

    public const CONTEXT_CLOSE = 'EXAMORA_CONTEXT>>>';

    public function name(): string
    {
        return 'mock';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $started = microtime(true);
        $context = $this->extractContext($request->prompt());

        $content = match ($request->task) {
            'lesson.generate' => json_encode($this->lesson($context)),
            'question.generate' => json_encode($this->questions($context)),
            'tutor.answer' => $this->tutorAnswer($context),
            'topic.classify' => json_encode($this->classify($context)),
            'document.ocr' => json_encode($this->transcribe($context, $request)),
            'document.parse' => json_encode($this->parseDocument($context)),
            default => json_encode(['ok' => true, 'task' => $request->task]),
        };

        return new LlmResponse(
            content: $content,
            provider: $this->name(),
            model: $this->model(),
            promptTokens: $this->estimateTokens($request->prompt()),
            completionTokens: $this->estimateTokens($content),
            latencyMs: (int) round((microtime(true) - $started) * 1000),
        );
    }

    /**
     * Hashing-trick bag-of-words vectors. Unlike random vectors these carry real
     * lexical similarity, so retrieval ranking behaves sensibly offline.
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function embed(array $texts): array
    {
        $dimensions = config('ai.embeddings.dimensions', 1536);

        return array_map(function (string $text) use ($dimensions) {
            $vector = array_fill(0, $dimensions, 0.0);
            $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            foreach ($tokens as $token) {
                $bucket = (int) hexdec(substr(md5($token), 0, 8)) % $dimensions;
                $sign = hexdec(substr(md5($token), 8, 2)) % 2 === 0 ? 1.0 : -1.0;
                $vector[$bucket] += $sign;
            }

            $norm = sqrt(array_sum(array_map(fn ($v) => $v * $v, $vector)));

            return $norm > 0 ? array_map(fn ($v) => $v / $norm, $vector) : $vector;
        }, array_values($texts));
    }

    private function extractContext(string $prompt): array
    {
        $pattern = '/'.preg_quote(self::CONTEXT_OPEN, '/').'(.*?)'.preg_quote(self::CONTEXT_CLOSE, '/').'/s';

        if (! preg_match($pattern, $prompt, $matches)) {
            return [];
        }

        return json_decode(trim($matches[1]), true) ?: [];
    }

    private function lesson(array $context): array
    {
        $topic = $context['topic'] ?? 'This Topic';
        $exam = $context['exam'] ?? 'the exam';
        $facts = $context['facts'] ?? [];
        $stats = $context['statistics'] ?? [];

        $keyPoints = [];
        $cards = [];

        foreach (array_slice($facts, 0, 6) as $index => $fact) {
            $keyPoints[] = $fact['answer_fact'] ?? $fact['stem'] ?? '';
            $cards[] = [
                'type' => $index === 0 ? 'fact' : ($index % 3 === 2 ? 'pitfall' : 'fact'),
                'title' => 'Exam point '.($index + 1),
                'body' => trim(($fact['answer_fact'] ?? '').' '.
                    (isset($fact['years']) ? 'Asked in '.implode(', ', (array) $fact['years']).'.' : '')),
            ];
        }

        if ($cards === []) {
            $cards[] = [
                'type' => 'fact',
                'title' => 'No past questions indexed yet',
                'body' => "Once past questions for {$topic} are ingested, this lesson will contain only the facts that have actually been examined.",
            ];
            $keyPoints[] = "{$topic}: awaiting past-question data.";
        }

        $cards[] = [
            'type' => 'mnemonic',
            'title' => 'Remember it this way',
            'body' => 'Chain the key points in the order they appear above — recall order matters more than volume under exam pressure.',
        ];

        return [
            'title' => $topic,
            'summary' => "The {$topic} facts that have actually appeared in {$exam}, and nothing else.",
            'estimated_minutes' => (int) ($stats['estimated_minutes'] ?? max(3, count($cards))),
            'key_points' => array_values(array_filter($keyPoints)),
            'cards' => $cards,
        ];
    }

    private function questions(array $context): array
    {
        $facts = $context['facts'] ?? [];
        $count = (int) ($context['count'] ?? 5);
        $questions = [];

        foreach (array_slice($facts, 0, $count) as $fact) {
            $correct = $fact['correct_option'] ?? 'Correct answer';
            $options = array_values(array_unique(array_merge(
                [$correct],
                array_slice($fact['distractors'] ?? ['Option B', 'Option C', 'Option D'], 0, 3)
            )));

            while (count($options) < 4) {
                $options[] = 'None of the above';
            }

            $questions[] = [
                'stem' => $fact['stem'] ?? 'Which statement is correct?',
                'options' => array_slice($options, 0, 4),
                'correct_index' => 0,
                'explanation' => $fact['explanation'] ?? 'This exact point has appeared in a previous paper.',
                'difficulty' => $fact['difficulty'] ?? 'medium',
            ];
        }

        return ['questions' => $questions];
    }

    private function tutorAnswer(array $context): string
    {
        $question = $context['question'] ?? 'your question';
        $topic = $context['topic'] ?? 'this topic';

        return "Short answer, exam level.\n\n"
            ."On {$topic}: {$question}\n\n"
            .'Remember the examined facts listed in your lesson — the exam tests recall of those specific points, not the wider theory.';
    }

    private function classify(array $context): array
    {
        $stem = mb_strtolower($context['stem'] ?? '');

        // Crude lexical overlap against the candidate topic names. Not semantic,
        // but it means the offline classifier lands on a plausible topic instead
        // of always picking the first candidate, so the pipeline's dedupe and
        // review paths get exercised realistically.
        $best = null;
        $bestScore = 0;

        foreach ($context['candidates'] ?? [] as $candidate) {
            $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($candidate['name'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $score = 0;

            foreach ($words as $word) {
                if (mb_strlen($word) > 3 && str_contains($stem, $word)) {
                    $score++;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        $best ??= $context['candidates'][0] ?? null;

        return [
            'topic_slug' => $best['slug'] ?? null,
            'confidence' => $bestScore > 0 ? min(0.95, 0.6 + $bestScore * 0.15) : 0.4,
        ];
    }

    /**
     * Stands in for the vision transcription of a scanned page. Returns the page
     * text the caller already handed us when there is one, so an offline run of
     * the ingestion pipeline still produces usable text.
     */
    private function transcribe(array $context, LlmRequest $request): array
    {
        $text = $context['page_text'] ?? null;

        if (! $text) {
            $page = $context['page_number'] ?? 1;
            $text = "[mock transcription of page {$page}]";
        }

        return [
            'text' => $text,
            'confidence' => 0.9,
            'attachments_seen' => count($request->attachments),
        ];
    }

    /**
     * Extracts questions from page text using the same numbered-question and
     * lettered-option layout real papers use. Deterministic, offline, and good
     * enough that the review queue has realistic rows to work with.
     */
    private function parseDocument(array $context): array
    {
        $text = $context['page_text'] ?? '';

        if (trim($text) === '') {
            return ['questions' => []];
        }

        $questions = [];

        // "1. Stem?  A) one B) two C) three D) four   Answer: B"
        $blocks = preg_split('/(?=^\s*\d+[.)]\s)/m', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($blocks as $block) {
            if (! preg_match('/^\s*\d+[.)]\s*(.+?)(?=\s*[A-D][.)]\s)/su', $block, $stemMatch)) {
                continue;
            }

            [$letters, $options] = $this->optionsIn($block);

            if (count($options) < 2) {
                continue;
            }

            $correctIndex = null;
            if (preg_match('/Answer:\s*([A-D])/i', $block, $answerMatch)) {
                $position = array_search(strtoupper($answerMatch[1]), $letters, true);
                $correctIndex = $position === false ? null : $position;
            }

            $explanation = null;
            if (preg_match('/(?:Explanation|Why):\s*(.+?)(?:\n|$)/su', $block, $explanationMatch)) {
                $explanation = trim($explanationMatch[1]);
            }

            $questions[] = [
                'stem' => trim(preg_replace('/\s+/', ' ', $stemMatch[1])),
                'options' => $options,
                'correct_index' => $correctIndex,
                'explanation' => $explanation,
                'difficulty' => 'medium',
                'confidence' => $correctIndex === null ? 0.55 : 0.92,
            ];
        }

        return ['questions' => $questions];
    }

    /**
     * Split an option line into its options.
     *
     * The hard part is that initials inside an option — "Colonel M. A. G.
     * Osmani" — are indistinguishable from option markers by pattern alone. Two
     * structural facts resolve it: markers run A, B, C, D in ascending order,
     * and an option's text runs to the next *kept* marker. So we locate every
     * candidate marker, keep only the ascending sequence, then slice between
     * those positions rather than trusting a lookahead.
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function optionsIn(string $block): array
    {
        if (! preg_match_all('/([A-D])[.)]\s/u', $block, $matches, PREG_OFFSET_CAPTURE)) {
            return [[], []];
        }

        $markers = [];
        $expected = 'A';

        foreach ($matches[1] as $index => [$letter, $offset]) {
            if ($letter !== $expected) {
                continue;
            }

            $markers[] = [
                'letter' => $letter,
                'start' => $offset,
                'textStart' => $matches[0][$index][1] + strlen($matches[0][$index][0]),
            ];

            $expected = chr(ord($expected) + 1);
        }

        // Options end where the answer key begins, if it is on the same block.
        $limit = preg_match('/\b(?:Answer|Explanation|Why)\s*:/u', $block, $stop, PREG_OFFSET_CAPTURE)
            ? $stop[0][1]
            : strlen($block);

        $letters = [];
        $options = [];

        foreach ($markers as $index => $marker) {
            $end = $markers[$index + 1]['start'] ?? $limit;
            $text = trim(substr($block, $marker['textStart'], max(0, $end - $marker['textStart'])));

            if ($text === '') {
                continue;
            }

            $letters[] = $marker['letter'];
            $options[] = preg_replace('/\s+/', ' ', $text);
        }

        return [$letters, $options];
    }
}
