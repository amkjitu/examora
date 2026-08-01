<?php

namespace App\Models\Examora;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class KnowledgeChunk extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_id', 'topic_id', 'source_type', 'source_id', 'title', 'content',
        'token_count', 'embedding_model', 'embedding', 'checksum', 'meta',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public static function usesPgVector(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /** @return array<int, float>|null */
    public function vector(): ?array
    {
        $raw = $this->attributes['embedding'] ?? null;

        if ($raw === null) {
            return null;
        }

        if (is_array($raw)) {
            return $raw;
        }

        // pgvector renders as "[0.1,0.2,...]"; the JSON fallback is the same shape.
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? array_map('floatval', $decoded) : null;
    }

    public function setVector(?array $vector): void
    {
        $this->attributes['embedding'] = $vector === null ? null : json_encode(array_values($vector));
    }
}
