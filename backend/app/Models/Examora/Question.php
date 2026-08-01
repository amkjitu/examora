<?php

namespace App\Models\Examora;

use App\Models\Scopes\VisibleQuestionScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Question extends Model
{
    use HasFactory;

    public const ORIGIN_PAST = 'past';

    public const ORIGIN_AI = 'ai_generated';

    public const ORIGIN_CURATED = 'curated';

    protected $fillable = [
        'exam_id', 'owner_id', 'topic_id', 'lesson_id', 'past_question_id', 'origin', 'stem',
        'options', 'correct_index', 'explanation', 'difficulty', 'times_served',
        'times_correct', 'p_value', 'is_active', 'fingerprint', 'meta',
    ];

    /**
     * Every read is filtered to the shared bank plus the caller's own private
     * questions. See VisibleQuestionScope for why this is global rather than
     * an explicit scope callers must remember.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new VisibleQuestionScope);
    }

    /**
     * Opt out of ownership filtering.
     *
     * Only for callers that legitimately span all users: the Filament admin, the
     * importance scorer, and maintenance commands. Never for anything that
     * serves questions to a student.
     */
    public static function withAllOwners(): Builder
    {
        return static::withoutGlobalScope(VisibleQuestionScope::class);
    }

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'meta' => 'array',
            'is_active' => 'boolean',
            'p_value' => 'float',
        ];
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

    public function pastQuestion(): BelongsTo
    {
        return $this->belongsTo(PastQuestion::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Only a student's own uploaded questions. */
    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('owner_id', $userId);
    }

    /**
     * The shared bank plus one specific user's private questions.
     *
     * Services that already receive a User should use this rather than relying
     * on the global scope's `auth()->id()`. A queue job or console command has
     * no authenticated user, and a service that silently returns a different
     * question set depending on how it was invoked is a bug waiting to happen.
     */
    public function scopeVisibleTo(Builder $query, int $userId): Builder
    {
        return $query->withoutGlobalScope(VisibleQuestionScope::class)
            ->where(fn (Builder $q) => $q->whereNull('owner_id')->orWhere('owner_id', $userId));
    }

    public function isPrivate(): bool
    {
        return $this->owner_id !== null;
    }

    public function isCorrect(int $selectedIndex): bool
    {
        return $selectedIndex === (int) $this->correct_index;
    }

    /** Never ship the answer key to the client on an unanswered question. */
    public function toStudentArray(): array
    {
        return [
            'id' => $this->id,
            'stem' => $this->stem,
            'options' => array_values($this->options ?? []),
            'difficulty' => $this->difficulty,
            'origin' => $this->origin,
            'topic_id' => $this->topic_id,
        ];
    }
}
