<?php

namespace App\Models\Examora;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PastQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_id', 'topic_id', 'year', 'session_label', 'stem', 'options',
        'correct_index', 'explanation', 'difficulty', 'marks', 'source',
        'fingerprint', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'meta' => 'array',
            'marks' => 'decimal:2',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    /**
     * Stable identity for a question regardless of year, punctuation or the
     * order the options were printed in. Lets us detect a question that has
     * been repeated across sessions — the single strongest importance signal.
     */
    public static function fingerprintFor(string $stem, array $options): string
    {
        $normalise = static fn (string $value): string => preg_replace(
            '/\s+/',
            ' ',
            mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', '', $value))
        );

        $parts = array_map($normalise, $options);
        sort($parts);

        return hash('sha256', $normalise($stem).'|'.implode('|', $parts));
    }

    public function correctOption(): ?string
    {
        return $this->options[$this->correct_index] ?? null;
    }
}
