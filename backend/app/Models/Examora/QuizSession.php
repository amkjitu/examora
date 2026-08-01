<?php

namespace App\Models\Examora;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class QuizSession extends Model
{
    use HasFactory;

    public const TYPE_LESSON_CHECK = 'lesson_check';

    public const TYPE_PRACTICE = 'practice';

    public const TYPE_REVISION = 'revision';

    public const TYPE_MOCK = 'mock';

    /** Real exam conditions: no feedback until the paper is submitted. */
    public const MODE_STRICT = 'strict';

    /** Learning conditions: right/wrong plus explanation after every answer. */
    public const MODE_PRACTICE = 'practice';

    protected $fillable = [
        'user_id', 'exam_id', 'topic_id', 'lesson_id', 'type', 'question_ids',
        'total', 'correct', 'score', 'duration_seconds', 'started_at', 'completed_at', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'question_ids' => 'array',
            'meta' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'score' => 'float',
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

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(QuizAnswer::class);
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * Only a timed mock runs in strict mode. Everything else teaches as it goes.
     */
    public function mode(): string
    {
        return $this->meta['mode'] ?? self::MODE_PRACTICE;
    }

    public function isStrict(): bool
    {
        return $this->mode() === self::MODE_STRICT;
    }

    public function expiresAt(): ?Carbon
    {
        $value = $this->meta['expires_at'] ?? null;

        return $value ? Carbon::parse($value) : null;
    }

    /**
     * Seconds left, computed from the wall clock rather than anything the client
     * reports. Closing the tab therefore gains the student nothing.
     */
    public function secondsRemaining(): ?int
    {
        $expiry = $this->expiresAt();

        if ($expiry === null) {
            return null;
        }

        return max(0, (int) now()->diffInSeconds($expiry, false));
    }

    public function hasExpired(): bool
    {
        return $this->secondsRemaining() === 0;
    }

    /** Marks deducted per wrong answer on this session's exam. */
    public function negativeMarking(): float
    {
        return $this->type === self::TYPE_MOCK
            ? (float) ($this->exam?->negative_marking ?? 0)
            : 0.0;
    }
}
