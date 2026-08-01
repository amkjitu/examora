<?php

namespace App\Models\Examora;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TopicMastery extends Model
{
    use HasFactory;

    protected $table = 'topic_mastery';

    protected $fillable = [
        'user_id', 'exam_id', 'topic_id', 'attempts', 'correct', 'accuracy',
        'mastery', 'coverage', 'lesson_completed', 'lesson_completed_at', 'last_studied_at',
    ];

    protected function casts(): array
    {
        return [
            'accuracy' => 'float',
            'mastery' => 'float',
            'coverage' => 'float',
            'lesson_completed' => 'boolean',
            'lesson_completed_at' => 'datetime',
            'last_studied_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }
}
