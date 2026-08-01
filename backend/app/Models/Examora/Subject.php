<?php

namespace App\Models\Examora;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    use HasFactory;

    protected $fillable = ['exam_id', 'name', 'slug', 'marks', 'position', 'description'];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function topics(): HasMany
    {
        return $this->hasMany(Topic::class)->orderBy('position');
    }

    /** Root-level topics only; chapters hang off these. */
    public function rootTopics(): HasMany
    {
        return $this->topics()->whereNull('parent_id');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
