<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Data\LlmRequest;
use App\Services\Ai\Data\LlmResponse;
use RuntimeException;

class GeminiProvider extends AbstractProvider
{
    public function name(): string
    {
        return 'gemini';
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $contents = array_map(fn (array $m) => [
            'role' => $m['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $m['content']]],
        ], $request->messages);

        // Gemini takes files as inline_data parts on the first user turn.
        if ($request->hasAttachments() && isset($contents[0])) {
            $files = array_map(fn ($attachment) => [
                'inline_data' => ['mime_type' => $attachment->mime, 'data' => $attachment->base64],
            ], $request->attachments);

            $contents[0]['parts'] = array_merge($files, $contents[0]['parts']);
        }

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'maxOutputTokens' => $request->maxTokens,
                'temperature' => $request->temperature,
            ],
        ];

        if ($request->system) {
            $payload['systemInstruction'] = ['parts' => [['text' => $request->system]]];
        }

        if ($request->schema) {
            $payload['generationConfig']['responseMimeType'] = 'application/json';
            $payload['generationConfig']['responseSchema'] = $this->toGeminiSchema($request->schema);
        }

        $started = microtime(true);

        $response = $this->http()->post(
            $this->baseUrl()."/models/{$this->model()}:generateContent?key=".$this->config['key'],
            $payload
        );

        if ($response->failed()) {
            throw new RuntimeException('Gemini request failed: '.$response->status().' '.$response->body());
        }

        $body = $response->json();
        $parts = $body['candidates'][0]['content']['parts'] ?? [];
        $usage = $body['usageMetadata'] ?? [];

        return new LlmResponse(
            content: implode('', array_column($parts, 'text')),
            provider: $this->name(),
            model: $this->model(),
            promptTokens: (int) ($usage['promptTokenCount'] ?? 0),
            completionTokens: (int) ($usage['candidatesTokenCount'] ?? 0),
            latencyMs: (int) round((microtime(true) - $started) * 1000),
        );
    }

    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $model = $this->config['embedding_model'] ?? 'text-embedding-004';

        $requests = array_map(fn (string $text) => [
            'model' => "models/{$model}",
            'content' => ['parts' => [['text' => $text]]],
        ], array_values($texts));

        $response = $this->http()->post(
            $this->baseUrl()."/models/{$model}:batchEmbedContents?key=".$this->config['key'],
            ['requests' => $requests]
        );

        if ($response->failed()) {
            throw new RuntimeException('Gemini embedding failed: '.$response->body());
        }

        return array_map(fn ($row) => $row['values'], $response->json('embeddings', []));
    }

    /** Gemini rejects the JSON Schema keywords OpenAI accepts; keep only what it knows. */
    private function toGeminiSchema(array $schema): array
    {
        $allowed = ['type', 'format', 'description', 'nullable', 'enum', 'items', 'properties', 'required'];
        $clean = array_intersect_key($schema, array_flip($allowed));

        if (isset($clean['properties'])) {
            $clean['properties'] = array_map(fn ($p) => $this->toGeminiSchema($p), $clean['properties']);
        }

        if (isset($clean['items'])) {
            $clean['items'] = $this->toGeminiSchema($clean['items']);
        }

        if (isset($clean['type'])) {
            $clean['type'] = strtoupper($clean['type']);
        }

        return $clean;
    }
}
