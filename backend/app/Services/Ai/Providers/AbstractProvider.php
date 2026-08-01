<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\LlmProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

abstract class AbstractProvider implements LlmProvider
{
    public function __construct(protected array $config) {}

    public function model(): string
    {
        return $this->config['model'] ?? 'unknown';
    }

    public function isConfigured(): bool
    {
        return filled($this->config['key'] ?? null);
    }

    public function costFor(int $promptTokens, int $completionTokens): float
    {
        $in = (float) ($this->config['cost']['input'] ?? 0);
        $out = (float) ($this->config['cost']['output'] ?? 0);

        return round(($promptTokens * $in + $completionTokens * $out) / 1_000_000, 6);
    }

    protected function http(): PendingRequest
    {
        return Http::timeout(config('ai.timeout', 60))
            ->retry(config('ai.retries', 2), 500, throw: false)
            ->acceptJson();
    }

    protected function baseUrl(): string
    {
        return rtrim($this->config['base_url'] ?? '', '/');
    }

    /**
     * Rough fallback when a provider omits usage data. Deliberately conservative
     * so the cost ledger never silently under-reports.
     */
    protected function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 3.5);
    }
}
