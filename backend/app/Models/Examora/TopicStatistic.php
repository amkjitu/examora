<?php

namespace App\Models\Examora;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TopicStatistic extends Model
{
    use HasFactory;

    protected $table = 'topic_statistics';

    protected $fillable = [
        'exam_id', 'topic_id', 'question_count', 'first_year', 'last_year', 'years',
        'frequency_per_year', 'recency_score', 'importance_score', 'probability_stars',
        'expected_questions_min', 'expected_questions_max', 'estimated_minutes', 'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'years' => 'array',
            'computed_at' => 'datetime',
            'frequency_per_year' => 'float',
            'recency_score' => 'float',
            'importance_score' => 'float',
        ];
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }
}
