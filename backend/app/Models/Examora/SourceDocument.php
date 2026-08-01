<?php

namespace App\Models\Examora;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceDocument extends Model
{
    use HasFactory;

    /** Admin-uploaded, verified material. Can enter the shared corpus. */
    public const VISIBILITY_SHARED = 'shared';

    /** A student's own upload. Never reaches the shared corpus on its own. */
    public const VISIBILITY_PRIVATE = 'private';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_EXTRACTED = 'extracted';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id', 'exam_id', 'subject_id', 'title', 'original_filename', 'path',
        'mime', 'size_bytes', 'page_count', 'pages_processed', 'visibility',
        'doc_type', 'status', 'year', 'session_label', 'checksum', 'error',
        'processed_at', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(DocumentPage::class)->orderBy('page_number');
    }

    public function extractedQuestions(): HasMany
    {
        return $this->hasMany(ExtractedQuestion::class);
    }

    public function scopeShared(Builder $query): Builder
    {
        return $query->where('visibility', self::VISIBILITY_SHARED);
    }

    public function isShared(): bool
    {
        return $this->visibility === self::VISIBILITY_SHARED;
    }

    /**
     * Only material from a shared document may be promoted into past_questions,
     * because past_questions drives topic importance for every student. A
     * student's private upload can power their own lessons and nothing else.
     */
    public function canEnterSharedCorpus(): bool
    {
        return $this->isShared();
    }

    public function progressPercent(): int
    {
        return $this->page_count > 0
            ? (int) round(($this->pages_processed / $this->page_count) * 100)
            : 0;
    }
}
