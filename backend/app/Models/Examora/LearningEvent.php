<?php

namespace App\Models\Examora;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LearningEvent extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'exam_id', 'type', 'xp', 'meta', 'occurred_at'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'occurred_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
