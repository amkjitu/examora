<?php

namespace App\Models\Examora;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LearnerProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'exam_id', 'xp', 'level', 'coins', 'streak_days', 'longest_streak',
        'last_active_on', 'accuracy', 'readiness', 'estimated_score', 'readiness_computed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_active_on' => 'date',
            'readiness_computed_at' => 'datetime',
            'accuracy' => 'float',
            'readiness' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    /**
     * Level curve: each level costs 20% more XP than the last, starting at 100.
     * Slow enough to stay meaningful, fast enough to reward the first session.
     */
    public static function levelForXp(int $xp): int
    {
        $level = 1;
        $threshold = 100;
        $spent = 0;

        while ($xp >= $spent + $threshold) {
            $spent += $threshold;
            $threshold = (int) round($threshold * 1.2);
            $level++;
        }

        return $level;
    }

    public function xpIntoLevel(): array
    {
        $level = 1;
        $threshold = 100;
        $spent = 0;

        while ($this->xp >= $spent + $threshold) {
            $spent += $threshold;
            $threshold = (int) round($threshold * 1.2);
            $level++;
        }

        return ['into' => $this->xp - $spent, 'needed' => $threshold];
    }
}
