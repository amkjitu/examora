<?php

namespace App\Models\Examora;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'task', 'provider', 'model', 'prompt_tokens', 'completion_tokens',
        'cost_usd', 'latency_ms', 'status', 'cache_hit', 'error', 'prompt_hash',
    ];

    protected function casts(): array
    {
        return ['cache_hit' => 'boolean', 'cost_usd' => 'float'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
