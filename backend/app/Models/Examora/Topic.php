<?php

namespace App\Models\Examora;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Topic extends Model
{
    use HasFactory;

    public const LEVEL_TOPIC = 'topic';

    public const LEVEL_CHAPTER = 'chapter';

    protected $fillable = [
        'subject_id', 'parent_id', 'name', 'slug', 'level',
        'description', 'syllabus_ref', 'position', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Topic::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Topic::class, 'parent_id')->orderBy('position');
    }

    public function statistics(): HasOne
    {
        return $this->hasOne(TopicStatistic::class);
    }

    public function pastQuestions(): HasMany
    {
        return $this->hasMany(PastQuestion::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    public function knowledgeChunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }

    public function scopeChapters(Builder $query): Builder
    {
        return $query->where('level', self::LEVEL_CHAPTER);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function examId(): ?int
    {
        return $this->subject?->exam_id;
    }

    // Route binding stays on the primary key: topic slugs are unique per
    // subject, not globally, so binding by slug would be ambiguous.
}
