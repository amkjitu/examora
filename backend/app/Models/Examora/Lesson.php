<?php

namespace App\Models\Examora;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lesson extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_id', 'topic_id', 'title', 'summary', 'locale', 'version',
        'estimated_minutes', 'key_points', 'status', 'generated_by',
        'prompt_hash', 'source_refs', 'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'key_points' => 'array',
            'source_refs' => 'array',
            'generated_at' => 'datetime',
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

    public function cards(): HasMany
    {
        return $this->hasMany(LessonCard::class)->orderBy('position');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }
}
