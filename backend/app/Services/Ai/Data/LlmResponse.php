<?php

namespace App\Services\Ai\Data;

use JsonException;

class LlmResponse
{
    public function __construct(
        public readonly string $content,
        public readonly string $provider,
        public readonly string $model,
        public readonly int $promptTokens = 0,
        public readonly int $completionTokens = 0,
        public readonly int $latencyMs = 0,
        public readonly bool $cacheHit = false,
        public readonly array $raw = [],
    ) {}

    public function withCacheHit(): self
    {
        return new self(
            $this->content,
            $this->provider,
            $this->model,
            $this->promptTokens,
            $this->completionTokens,
            $this->latencyMs,
            true,
            $this->raw,
        );
    }

    /**
     * Models like to wrap JSON in prose or fences no matter how firmly you ask
     * them not to. Strip the wrapper, then take the outermost balanced object.
     */
    public function json(): array
    {
        $text = trim($this->content);

        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $text, $matches)) {
            $text = $matches[1];
        }

        $start = strcspn($text, '{[');
        if ($start < strlen($text)) {
            $text = substr($text, $start);
        }

        $end = max(strrpos($text, '}') ?: -1, strrpos($text, ']') ?: -1);
        if ($end >= 0) {
            $text = substr($text, 0, $end + 1);
        }

        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new \RuntimeException(
                "LLM returned unparsable JSON for provider {$this->provider}: {$e->getMessage()}"
            );
        }

        return is_array($decoded) ? $decoded : [];
    }

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }
}
