<?php

namespace App\Services\Ai\Data;

/**
 * A document or image sent alongside a prompt.
 *
 * Every current provider accepts PDFs and images natively, which is why the
 * pipeline has no local OCR engine and no Ghostscript dependency: a scanned page
 * goes to the model as-is and comes back as text.
 */
class Attachment
{
    public function __construct(
        public readonly string $mime,
        public readonly string $base64,
        public readonly ?string $filename = null,
    ) {}

    public static function fromPath(string $path, ?string $mime = null): self
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new \RuntimeException("Unable to read attachment at [{$path}].");
        }

        return new self(
            mime: $mime ?? (mime_content_type($path) ?: 'application/octet-stream'),
            base64: base64_encode($contents),
            filename: basename($path),
        );
    }

    public static function fromContents(string $contents, string $mime, ?string $filename = null): self
    {
        return new self($mime, base64_encode($contents), $filename);
    }

    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf';
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    public function checksum(): string
    {
        return hash('sha256', $this->base64);
    }

    public function bytes(): int
    {
        return (int) (strlen($this->base64) * 3 / 4);
    }
}
