<?php

namespace App\Services\Ai;

use App\Models\Examora\AiRequest;
use App\Services\Ai\Contracts\LlmProvider;
use App\Services\Ai\Data\LlmRequest;
use App\Services\Ai\Data\LlmResponse;
use App\Services\Ai\Providers\AnthropicProvider;
use App\Services\Ai\Providers\GeminiProvider;
use App\Services\Ai\Providers\MockProvider;
use App\Services\Ai\Providers\OpenAiProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The single door to every LLM.
 *
 * Responsibilities, in order of importance to the business:
 *   1. Never let one vendor be a single point of failure (route + fallback).
 *   2. Never pay twice for the same prompt (content-addressed cache).
 *   3. Always know what was spent, on what, by whom (ai_requests ledger).
 *
 * Nothing in the application layer names a provider directly.
 */
class AiGateway
{
    /** @var array<string, LlmProvider> */
    private array $resolved = [];

    private const DRIVERS = [
        'openai' => OpenAiProvider::class,
        'gemini' => GeminiProvider::class,
        'anthropic' => AnthropicProvider::class,
        'mock' => MockProvider::class,
    ];

    public function complete(LlmRequest $request): LlmResponse
    {
        $hash = $request->hash();
        $ttl = (int) config('ai.cache_ttl', 0);

        if ($ttl > 0 && ($cached = Cache::get($this->cacheKey($hash)))) {
            $response = $this->hydrate($cached)->withCacheHit();
            $this->record($request, $response, 'ok', null, $hash);

            return $response;
        }

        $errors = [];

        foreach ($this->providerChain($request->task) as $provider) {
            try {
                $response = $provider->complete($request);

                if ($ttl > 0) {
                    Cache::put($this->cacheKey($hash), $this->dehydrate($response), $ttl);
                }

                $this->record($request, $response, $errors === [] ? 'ok' : 'fallback', null, $hash);

                return $response;
            } catch (Throwable $e) {
                $errors[$provider->name()] = $e->getMessage();
                Log::warning('AI provider failed, trying next', [
                    'task' => $request->task,
                    'provider' => $provider->name(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->recordFailure($request, $errors, $hash);

        throw new RuntimeException(
            "All providers failed for task {$request->task}: ".json_encode($errors)
        );
    }

    /**
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        foreach ($this->providerChain('embed') as $provider) {
            try {
                return $provider->embed($texts);
            } catch (Throwable $e) {
                Log::warning('Embedding provider failed', [
                    'provider' => $provider->name(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Mock embeddings are lexical, not semantic, but they keep retrieval
        // functional rather than returning nothing at all.
        return $this->provider('mock')->embed($texts);
    }

    public function embedOne(string $text): array
    {
        return $this->embed([$text])[0] ?? [];
    }

    /**
     * Ordered list of configured providers for a task, always ending in a
     * usable fallback so a call can degrade but never hard-fail on config.
     *
     * @return array<int, LlmProvider>
     */
    public function providerChain(string $task): array
    {
        $preferred = config("ai.tasks.{$task}.providers", []);
        $names = array_merge($preferred, [config('ai.default'), config('ai.fallback'), 'mock']);

        $chain = [];

        foreach (array_unique(array_filter($names)) as $name) {
            if (! isset(self::DRIVERS[$name]) && ! config("ai.providers.{$name}")) {
                continue;
            }

            $provider = $this->provider($name);

            if ($provider->isConfigured() && ! isset($chain[$name])) {
                $chain[$name] = $provider;
            }
        }

        return array_values($chain);
    }

    public function provider(string $name): LlmProvider
    {
        return $this->resolved[$name] ??= $this->make($name);
    }

    private function make(string $name): LlmProvider
    {
        $config = config("ai.providers.{$name}");

        if (! $config) {
            throw new RuntimeException("Unknown AI provider [{$name}].");
        }

        $class = self::DRIVERS[$config['driver'] ?? $name] ?? null;

        if (! $class) {
            throw new RuntimeException("No driver registered for AI provider [{$name}].");
        }

        return new $class($config);
    }

    private function record(LlmRequest $request, LlmResponse $response, string $status, ?string $error, string $hash): void
    {
        try {
            AiRequest::create([
                'user_id' => $request->userId,
                'task' => $request->task,
                'provider' => $response->provider,
                'model' => $response->model,
                'prompt_tokens' => $response->promptTokens,
                'completion_tokens' => $response->completionTokens,
                'cost_usd' => $response->cacheHit
                    ? 0.0
                    : $this->provider($response->provider)->costFor($response->promptTokens, $response->completionTokens),
                'latency_ms' => $response->latencyMs,
                'status' => $status,
                'cache_hit' => $response->cacheHit,
                'error' => $error,
                'prompt_hash' => $hash,
            ]);
        } catch (Throwable $e) {
            // Accounting must never take down a student's lesson.
            Log::error('Failed to record AI request', ['error' => $e->getMessage()]);
        }
    }

    private function recordFailure(LlmRequest $request, array $errors, string $hash): void
    {
        try {
            AiRequest::create([
                'user_id' => $request->userId,
                'task' => $request->task,
                'provider' => 'none',
                'model' => 'none',
                'status' => 'error',
                'error' => json_encode($errors),
                'prompt_hash' => $hash,
            ]);
        } catch (Throwable) {
            // ignored
        }
    }

    private function cacheKey(string $hash): string
    {
        return "ai:completion:{$hash}";
    }

    private function dehydrate(LlmResponse $response): array
    {
        return [
            'content' => $response->content,
            'provider' => $response->provider,
            'model' => $response->model,
            'prompt_tokens' => $response->promptTokens,
            'completion_tokens' => $response->completionTokens,
            'latency_ms' => $response->latencyMs,
        ];
    }

    private function hydrate(array $data): LlmResponse
    {
        return new LlmResponse(
            content: $data['content'],
            provider: $data['provider'],
            model: $data['model'],
            promptTokens: $data['prompt_tokens'] ?? 0,
            completionTokens: $data['completion_tokens'] ?? 0,
            latencyMs: $data['latency_ms'] ?? 0,
        );
    }
}
