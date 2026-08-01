<?php

namespace App\Models\Examora;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question the pipeline believes it found. Nothing here is trusted.
 *
 * An extracted question with a wrong answer key poisons the importance score,
 * the lesson and the readiness estimate at once, so promotion into
 * past_questions is always a deliberate human act.
 */
class ExtractedQuestion extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_DUPLICATE = 'duplicate';

    protected $fillable = [
        'source_document_id', 'exam_id', 'topic_id', 'page_number', 'year',
        'session_label', 'stem', 'options', 'correct_index', 'explanation',
        'difficulty', 'confidence', 'topic_confidence', 'status', 'fingerprint',
        'duplicate_of_id', 'past_question_id', 'reviewed_by', 'reviewed_at',
        'review_note', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'meta' => 'array',
            'reviewed_at' => 'datetime',
            'confidence' => 'float',
            'topic_confidence' => 'float',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(SourceDocument::class, 'source_document_id');
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(PastQuestion::class, 'duplicate_of_id');
    }

    public function pastQuestion(): BelongsTo
    {
        return $this->belongsTo(PastQuestion::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Rows a reviewer should look at hardest: low transcription confidence, an
     * unclassified topic, or a missing answer key.
     */
    public function needsAttention(): bool
    {
        return ($this->confidence !== null && $this->confidence < 0.7)
            || $this->topic_id === null
            || $this->correct_index === null;
    }

    public function isReviewable(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
