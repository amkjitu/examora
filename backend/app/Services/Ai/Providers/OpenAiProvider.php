<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Data\LlmRequest;
use App\Services\Ai\Data\LlmResponse;
use RuntimeException;

class OpenAiProvider extends AbstractProvider
{
    public function name(): string
    {
        return 'openai';
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $messages = [];

        if ($request->system) {
            $messages[] = ['role' => 'system', 'content' => $request->system];
        }

        foreach ($request->messages as $index => $message) {
            // OpenAI takes files as content parts: `file` for PDFs, `image_url`
            // with a data URI for images. Only the first user turn carries them.
            if ($index === 0 && $request->hasAttachments()) {
                $parts = [];

                foreach ($request->attachments as $attachment) {
                    $parts[] = $attachment->isPdf()
                        ? [
                            'type' => 'file',
                            'file' => [
                                'filename' => $attachment->filename ?? 'document.pdf',
                                'file_data' => 'data:'.$attachment->mime.';base64,'.$attachment->base64,
                            ],
                        ]
                        : [
                            'type' => 'image_url',
                            'image_url' => ['url' => 'data:'.$attachment->mime.';base64,'.$attachment->base64],
                        ];
                }

                $parts[] = ['type' => 'text', 'text' => $message['content']];
                $messages[] = ['role' => $message['role'], 'content' => $parts];

                continue;
            }

            $messages[] = $message;
        }

        $payload = [
            'model' => $this->model(),
            'messages' => $messages,
            'max_tokens' => $request->maxTokens,
            'temperature' => $request->temperature,
        ];

        if ($request->schema) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => str_replace('.', '_', $request->task),
                    'strict' => false,
                    'schema' => $request->schema,
                ],
            ];
        }

        $started = microtime(true);

        $response = $this->http()
            ->withToken($this->config['key'])
            ->post($this->baseUrl().'/chat/completions', $payload);

        if ($response->failed()) {
            throw new RuntimeException('OpenAI request failed: '.$response->status().' '.$response->body());
        }

        $body = $response->json();
        $usage = $body['usage'] ?? [];

        return new LlmResponse(
            content: $body['choices'][0]['message']['content'] ?? '',
            provider: $this->name(),
            model: $body['model'] ?? $this->model(),
            promptTokens: (int) ($usage['prompt_tokens'] ?? 0),
            completionTokens: (int) ($usage['completion_tokens'] ?? 0),
            latencyMs: (int) round((microtime(true) - $started) * 1000),
        );
    }

    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $response = $this->http()
            ->withToken($this->config['key'])
            ->post($this->baseUrl().'/embeddings', [
                'model' => $this->config['embedding_model'] ?? 'text-embedding-3-small',
                'input' => array_values($texts),
                'dimensions' => config('ai.embeddings.dimensions', 1536),
            ]);

        if ($response->failed()) {
            throw new RuntimeException('OpenAI embedding failed: '.$response->body());
        }

        $data = $response->json('data', []);
        usort($data, fn ($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        return array_map(fn ($row) => $row['embedding'], $data);
    }
}
