<?php

namespace App\Models;

use App\Models\Examora\ExamEnrollment;
use App\Models\Examora\LearnerProfile;
use App\Models\Examora\MemoryItem;
use App\Models\Examora\QuizSession;
use App\Models\Examora\TopicMastery;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Wave\Traits\HasProfileKeyValues;
use Wave\User as WaveUser;

class User extends WaveUser
{
    use HasFactory, HasProfileKeyValues, Notifiable, SoftDeletes;

    public $guard_name = 'web';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'username',
        'avatar',
        'password',
        'role_id',
        'verification_code',
        'verified',
        'trial_ends_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'notification_preferences' => 'array',
            'social_links' => 'array',
            'privacy_settings' => 'array',
            'deletion_scheduled_at' => 'datetime',
        ];
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }

    // ---------------------------------------------------------------------
    // Examora
    // ---------------------------------------------------------------------

    public function enrollments()
    {
        return $this->hasMany(ExamEnrollment::class);
    }

    public function activeEnrollment()
    {
        return $this->hasOne(ExamEnrollment::class)->where('is_active', true);
    }

    public function learnerProfiles()
    {
        return $this->hasMany(LearnerProfile::class);
    }

    public function memoryItems()
    {
        return $this->hasMany(MemoryItem::class);
    }

    public function quizSessions()
    {
        return $this->hasMany(QuizSession::class);
    }

    public function topicMastery()
    {
        return $this->hasMany(TopicMastery::class);
    }

    public function profileFor(int $examId): LearnerProfile
    {
        return LearnerProfile::firstOrCreate(
            ['user_id' => $this->id, 'exam_id' => $examId],
            ['xp' => 0, 'level' => 1, 'coins' => 0, 'streak_days' => 0]
        );
    }

    /**
     * Wave gives us subscriptions and trials; Examora adds the free tier, which
     * is metered rather than blocked. Anyone signed in has *some* access.
     */
    public function hasPremiumAccess(): bool
    {
        return $this->subscriber() || $this->onTrial();
    }

    protected static function boot()
    {
        parent::boot();

        // Listen for the creating event of the model
        static::creating(function ($user) {
            // Check if the username attribute is empty
            if (empty($user->username)) {
                // Use the name to generate a slugified username
                $username = Str::slug($user->name, '');
                $i = 1;
                while (self::where('username', $username)->exists()) {
                    $username = Str::slug($user->name, '').$i;
                    $i++;
                }
                $user->username = $username;
            }
        });

        // Listen for the created event of the model
        static::created(function ($user) {
            // Remove all roles
            $user->syncRoles([]);

            // Assign the default role if it exists
            $defaultRole = config('wave.default_user_role', 'registered');
            if (Role::where('name', $defaultRole)->where('guard_name', 'web')->exists()) {
                $user->assignRole($defaultRole);
            }
        });
    }
}
