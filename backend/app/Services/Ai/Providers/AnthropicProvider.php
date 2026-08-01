<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Data\LlmRequest;
use App\Services\Ai\Data\LlmResponse;
use RuntimeException;

class AnthropicProvider extends AbstractProvider
{
    public function name(): string
    {
        return 'anthropic';
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $payload = [
            'model' => $this->model(),
            'max_tokens' => $request->maxTokens,
            'temperature' => $request->temperature,
            'messages' => $request->hasAttachments()
                ? $this->messagesWithAttachments($request)
                : $request->messages,
        ];

        if ($request->system) {
            $payload['system'] = $request->system;
        }

        // Claude has no response_format; a forced single-tool call is the
        // supported way to guarantee schema-shaped output.
        if ($request->schema) {
            $toolName = str_replace('.', '_', $request->task);
            $payload['tools'] = [[
                'name' => $toolName,
                'description' => 'Return the structured result.',
                'input_schema' => $request->schema,
            ]];
            $payload['tool_choice'] = ['type' => 'tool', 'name' => $toolName];
        }

        $started = microtime(true);

        $response = $this->http()
            ->withHeaders([
                'x-api-key' => $this->config['key'],
                'anthropic-version' => $this->config['version'] ?? '2023-06-01',
            ])
            ->post($this->baseUrl().'/messages', $payload);

        if ($response->failed()) {
            throw new RuntimeException('Anthropic request failed: '.$response->status().' '.$response->body());
        }

        $body = $response->json();
        $usage = $body['usage'] ?? [];
        $content = '';

        foreach ($body['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $content .= $block['text'];
            }

            if (($block['type'] ?? '') === 'tool_use') {
                $content = json_encode($block['input'] ?? []);
                break;
            }
        }

        return new LlmResponse(
            content: $content,
            provider: $this->name(),
            model: $body['model'] ?? $this->model(),
            promptTokens: (int) ($usage['input_tokens'] ?? 0),
            completionTokens: (int) ($usage['output_tokens'] ?? 0),
            latencyMs: (int) round((microtime(true) - $started) * 1000),
        );
    }

    /**
     * Claude takes PDFs as `document` blocks and images as `image` blocks, both
     * base64. Attachments ride on the first user message so the instructions
     * that follow them apply to the file.
     */
    private function messagesWithAttachments(LlmRequest $request): array
    {
        $blocks = [];

        foreach ($request->attachments as $attachment) {
            $blocks[] = [
                'type' => $attachment->isPdf() ? 'document' : 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $attachment->mime,
                    'data' => $attachment->base64,
                ],
            ];
        }

        $messages = $request->messages;
        $first = array_shift($messages) ?: ['role' => 'user', 'content' => ''];

        $blocks[] = ['type' => 'text', 'text' => $first['content']];

        return array_merge([['role' => 'user', 'content' => $blocks]], $messages);
    }

    public function embed(array $texts): array
    {
        // Anthropic ships no embedding endpoint. The gateway's `embed` route
        // never lists this provider; this guard exists so a misconfiguration
        // fails loudly instead of returning silent garbage vectors.
        throw new RuntimeException('Anthropic does not provide embeddings; route the `embed` task to openai or gemini.');
    }
}
