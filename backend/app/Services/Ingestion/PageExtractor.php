<?php

namespace App\Services\Ingestion;

use App\Models\Examora\DocumentPage;
use App\Models\Examora\SourceDocument;
use App\Services\Ai\AiGateway;
use App\Services\Ai\Data\Attachment;
use App\Services\Ai\Data\LlmRequest;
use App\Services\Ai\PromptContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Gets text out of a document, one page at a time.
 *
 * Two routes, cheapest first:
 *
 *   1. Text layer. Most typed question banks and guide-book PDFs carry real
 *      text. Pulling it is free, instant and exact.
 *   2. Vision. Scans have no text layer, and Bangla scans in particular defeat
 *      classical OCR. Those pages go to a vision model through the gateway.
 *
 * There is deliberately no Tesseract or Ghostscript dependency: every provider
 * accepts a PDF natively, so a scan is forwarded as-is.
 */
class PageExtractor
{
    private const OCR_SYSTEM = <<<'TXT'
    You transcribe scanned exam papers.

    Rules:
    1. Transcribe every question, option and answer exactly as printed.
    2. Preserve question numbering and option letters.
    3. Preserve the original script. Do not translate Bangla into English.
    4. Ignore page furniture: headers, footers, page numbers, watermarks.
    5. If a page is blank or unreadable, return an empty string for text.

    Return JSON only.
    TXT;

    public function __construct(private readonly AiGateway $ai) {}

    /**
     * @return int number of pages that now have text
     */
    public function extract(SourceDocument $document): int
    {
        $path = Storage::path($document->path);

        if (! is_file($path)) {
            throw new RuntimeException("Source file for document {$document->id} is missing.");
        }

        $pages = $this->textLayerPages($path);

        $document->update([
            'page_count' => max($document->page_count, count($pages)),
        ]);

        $extracted = 0;

        foreach ($pages as $number => $text) {
            $page = DocumentPage::firstOrNew([
                'source_document_id' => $document->id,
                'page_number' => $number,
            ]);

            // Already transcribed on a previous run — never re-read, never re-bill.
            if ($page->exists && ! $page->looksEmpty()) {
                $extracted++;

                continue;
            }

            $page->fill([
                'text' => $text,
                'extraction_method' => DocumentPage::METHOD_TEXT_LAYER,
            ]);

            if ($page->looksEmpty()) {
                $vision = $this->transcribeWithVision($document, $path, $number);

                if ($vision !== null) {
                    $page->text = $vision;
                    $page->extraction_method = DocumentPage::METHOD_VISION;
                }
            }

            $page->token_count = (int) ceil(mb_strlen((string) $page->text) / 3.5);
            $page->save();

            if (! $page->looksEmpty()) {
                $extracted++;
            }

            $document->increment('pages_processed');
        }

        return $extracted;
    }

    /**
     * @return array<int, string> page number (1-based) => text
     */
    private function textLayerPages(string $path): array
    {
        try {
            $pdf = (new Parser)->parseFile($path);
            $pages = [];

            foreach ($pdf->getPages() as $index => $page) {
                $pages[$index + 1] = trim($page->getText());
            }

            return $pages === [] ? [1 => ''] : $pages;
        } catch (Throwable $e) {
            // A PDF the parser cannot open is not fatal — it just means every
            // page has to go the vision route.
            Log::warning('PDF text layer unreadable, falling back to vision', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return [1 => ''];
        }
    }

    private function transcribeWithVision(SourceDocument $document, string $path, int $pageNumber): ?string
    {
        try {
            $context = [
                'page_number' => $pageNumber,
                'exam' => $document->exam?->name,
                'document_type' => $document->doc_type,
            ];

            $request = LlmRequest::make(
                'document.ocr',
                "Transcribe page {$pageNumber} of this document.\n\n".PromptContext::block($context),
                self::OCR_SYSTEM,
            )
                ->withSchema([
                    'type' => 'object',
                    'properties' => [
                        'text' => ['type' => 'string'],
                        'confidence' => ['type' => 'number'],
                    ],
                    'required' => ['text'],
                ])
                ->withAttachments([Attachment::fromPath($path, $document->mime)])
                ->forUser($document->user_id);

            $text = trim((string) ($this->ai->complete($request)->json()['text'] ?? ''));

            return $text === '' ? null : $text;
        } catch (Throwable $e) {
            Log::warning('Vision transcription failed', [
                'document_id' => $document->id,
                'page' => $pageNumber,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
