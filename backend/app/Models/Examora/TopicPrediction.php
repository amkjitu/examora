<?php

namespace App\Models\Examora;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TopicPrediction extends Model
{
    use HasFactory;

    public const TREND_RISING = 'rising';

    public const TREND_STEADY = 'steady';

    public const TREND_FALLING = 'falling';

    protected $fillable = [
        'exam_id', 'topic_id', 'target_year', 'appearance_probability',
        'expected_questions', 'expected_low', 'expected_high', 'confidence',
        'trend', 'overdue_ratio', 'rank', 'signals', 'evidence',
        'model_version', 'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'signals' => 'array',
            'evidence' => 'array',
            'computed_at' => 'datetime',
            'appearance_probability' => 'float',
            'expected_questions' => 'float',
            'expected_low' => 'float',
            'expected_high' => 'float',
            'confidence' => 'float',
            'overdue_ratio' => 'float',
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

    /**
     * Confidence bands, because a bare percentage invites false precision.
     * A student should be able to tell a twelve-year pattern from a coincidence.
     */
    public function confidenceLabel(): string
    {
        return match (true) {
            $this->confidence >= 0.7 => 'high',
            $this->confidence >= 0.45 => 'moderate',
            $this->confidence >= 0.25 => 'low',
            default => 'very low',
        };
    }

    /** A topic is "due" when it has gone longer than its own usual gap. */
    public function isOverdue(): bool
    {
        return $this->overdue_ratio >= 1.2;
    }
}
