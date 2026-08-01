<?php

namespace App\Models\Examora;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonCard extends Model
{
    use HasFactory;

    protected $fillable = ['lesson_id', 'position', 'type', 'title', 'body', 'meta'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
