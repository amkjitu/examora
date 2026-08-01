<?php

namespace App\Models\Examora;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudyPlanItem extends Model
{
    use HasFactory;

    public const TYPE_LESSON = 'lesson';

    public const TYPE_PRACTICE = 'practice';

    public const TYPE_REVISION = 'revision';

    public const TYPE_MOCK = 'mock';

    protected $fillable = [
        'study_plan_id', 'topic_id', 'lesson_id', 'type', 'position',
        'estimated_minutes', 'status', 'reason', 'payload', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(StudyPlan::class, 'study_plan_id');
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
