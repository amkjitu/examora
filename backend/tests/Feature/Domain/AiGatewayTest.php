<?php

use App\Models\Examora\AiRequest;
use App\Services\Ai\AiGateway;
use App\Services\Ai\Data\LlmRequest;
use App\Services\Ai\Data\LlmResponse;
use App\Services\Knowledge\Retriever;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('falls back to the next provider when the preferred one fails', function () {
    config([
        'ai.providers.openai.key' => 'sk-test',
        'ai.tasks.tutor_test' => ['providers' => ['openai', 'mock'], 'max_tokens' => 100, 'temperature' => 0.0],
    ]);

    Http::fake([
        'api.openai.com/*' => Http::response(['error' => 'boom'], 500),
    ]);

    $response = app(AiGateway::class)->complete(
        LlmRequest::make('tutor_test', 'Explain osmosis')
    );

    expect($response->provider)->toBe('mock');

    $record = AiRequest::latest('id')->first();
    expect($record->status)->toBe('fallback')
        ->and($record->provider)->toBe('mock');
});

it('skips providers that have no api key without erroring', function () {
    config([
        'ai.providers.openai.key' => null,
        'ai.providers.anthropic.key' => null,
        'ai.providers.gemini.key' => null,
    ]);

    $chain = app(AiGateway::class)->providerChain('lesson.generate');

    expect($chain)->toHaveCount(1)
        ->and($chain[0]->name())->toBe('mock');
});

it('serves a repeated prompt from cache and bills it at zero', function () {
    config(['ai.cache_ttl' => 600]);
    Cache::flush();

    $gateway = app(AiGateway::class);
    $request = LlmRequest::make('lesson.generate', 'Same prompt every time');

    $first = $gateway->complete($request);
    $second = $gateway->complete($request);

    expect($first->cacheHit)->toBeFalse()
        ->and($second->cacheHit)->toBeTrue()
        ->and($second->content)->toBe($first->content);

    expect(AiRequest::where('cache_hit', true)->where('cost_usd', 0)->count())->toBe(1);
});

it('extracts json even when the model wraps it in prose and fences', function () {
    $response = new LlmResponse(
        content: "Sure! Here you go:\n```json\n{\"title\":\"Liberation War\",\"key_points\":[\"a\"]}\n```\nHope that helps.",
        provider: 'test',
        model: 'test',
    );

    expect($response->json())->toBe(['title' => 'Liberation War', 'key_points' => ['a']]);
});

it('produces embeddings that rank similar text higher than unrelated text', function () {
    $gateway = app(AiGateway::class);

    [$war, $warAgain, $unrelated] = $gateway->embed([
        'The Liberation War of Bangladesh began in March 1971',
        'Bangladesh Liberation War 1971 sectors and commanders',
        'Photosynthesis converts light energy into chemical energy in plants',
    ]);

    $similar = Retriever::cosine($war, $warAgain);
    $different = Retriever::cosine($war, $unrelated);

    expect($similar)->toBeGreaterThan($different);
});
