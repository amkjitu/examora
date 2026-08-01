<?php

namespace App\Models\Examora;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentPage extends Model
{
    use HasFactory;

    public const METHOD_TEXT_LAYER = 'text_layer';

    public const METHOD_VISION = 'vision';

    protected $fillable = [
        'source_document_id', 'page_number', 'text', 'extraction_method', 'token_count',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(SourceDocument::class, 'source_document_id');
    }

    /**
     * A page with almost no text is either blank or a scan whose text layer is
     * missing — either way the text-layer pass has nothing useful and the page
     * needs the vision route.
     */
    public function looksEmpty(): bool
    {
        return mb_strlen(trim((string) $this->text)) < 40;
    }
}
