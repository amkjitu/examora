<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\Data\LlmRequest;
use App\Services\Ai\Data\LlmResponse;

interface LlmProvider
{
    public function name(): string;

    public function model(): string;

    /** False when the provider has no API key — the gateway skips it silently. */
    public function isConfigured(): bool;

    public function complete(LlmRequest $request): LlmResponse;

    /**
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function embed(array $texts): array;

    public function costFor(int $promptTokens, int $completionTokens): float;
}
