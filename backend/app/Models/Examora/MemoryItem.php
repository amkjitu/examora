<?php

namespace App\Models\Examora;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemoryItem extends Model
{
    use HasFactory;

    public const STATE_NEW = 'new';

    public const STATE_LEARNING = 'learning';

    public const STATE_REVIEW = 'review';

    public const STATE_RELEARNING = 'relearning';

    protected $fillable = [
        'user_id', 'question_id', 'topic_id', 'exam_id', 'state', 'ease_factor',
        'interval_days', 'repetitions', 'lapses', 'due_at', 'last_reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'last_reviewed_at' => 'datetime',
            'ease_factor' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function scopeDue(Builder $query, ?\DateTimeInterface $at = null): Builder
    {
        return $query->whereNotNull('due_at')->where('due_at', '<=', $at ?? now());
    }
}
