<?php

namespace App\Models\Examora;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamEnrollment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'exam_id', 'target_session', 'target_date',
        'daily_minutes_goal', 'is_active', 'started_at',
    ];

    protected function casts(): array
    {
        return [
            'target_date' => 'date',
            'started_at' => 'datetime',
            'is_active' => 'boolean',
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

    public function daysLeft(): ?int
    {
        return $this->target_date
            ? max(0, (int) now()->startOfDay()->diffInDays($this->target_date->startOfDay(), false))
            : null;
    }
}
