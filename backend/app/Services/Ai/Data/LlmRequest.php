<?php

namespace App\Services\Ai\Data;

class LlmRequest
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>|null  $schema  JSON schema the response must satisfy
     * @param  array<int, Attachment>  $attachments  documents or images sent alongside the prompt
     */
    public function __construct(
        public readonly string $task,
        public readonly array $messages,
        public readonly ?string $system = null,
        public readonly int $maxTokens = 2000,
        public readonly float $temperature = 0.3,
        public readonly ?array $schema = null,
        public readonly ?int $userId = null,
        public readonly array $attachments = [],
    ) {}

    public static function make(string $task, string $prompt, ?string $system = null): self
    {
        $config = config("ai.tasks.{$task}", []);

        return new self(
            task: $task,
            messages: [['role' => 'user', 'content' => $prompt]],
            system: $system,
            maxTokens: $config['max_tokens'] ?? 2000,
            temperature: $config['temperature'] ?? 0.3,
        );
    }

    public function withSchema(array $schema): self
    {
        return $this->copyWith(schema: $schema);
    }

    public function forUser(?int $userId): self
    {
        return $this->copyWith(userId: $userId);
    }

    /**
     * @param  array<int, Attachment>  $attachments
     */
    public function withAttachments(array $attachments): self
    {
        return $this->copyWith(attachments: $attachments);
    }

    public function hasAttachments(): bool
    {
        return $this->attachments !== [];
    }

    /**
     * Stable cache key across providers — the prompt, not the provider, is the
     * identity. Attachments contribute their checksum rather than their bytes so
     * the key stays small while still changing when the file does.
     */
    public function hash(): string
    {
        return hash('sha256', json_encode([
            $this->task,
            $this->system,
            $this->messages,
            $this->maxTokens,
            round($this->temperature, 2),
            $this->schema,
            array_map(fn (Attachment $a) => $a->checksum(), $this->attachments),
        ]));
    }

    public function prompt(): string
    {
        return implode("\n\n", array_column($this->messages, 'content'));
    }

    private function copyWith(
        ?array $schema = null,
        ?int $userId = null,
        ?array $attachments = null,
    ): self {
        return new self(
            $this->task,
            $this->messages,
            $this->system,
            $this->maxTokens,
            $this->temperature,
            $schema ?? $this->schema,
            $userId ?? $this->userId,
            $attachments ?? $this->attachments,
        );
    }
}
