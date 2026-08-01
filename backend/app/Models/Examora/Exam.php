<?php

namespace App\Models\Examora;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exam extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'slug', 'authority', 'locale', 'description',
        'total_marks', 'duration_minutes', 'negative_marking', 'is_active', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'is_active' => 'boolean',
            'negative_marking' => 'decimal:2',
        ];
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class)->orderBy('position');
    }

    public function topics()
    {
        return $this->hasManyThrough(Topic::class, Subject::class);
    }

    public function pastQuestions(): HasMany
    {
        return $this->hasMany(PastQuestion::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
