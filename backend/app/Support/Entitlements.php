<?php

namespace App\Support;

use App\Models\Examora\LearningEvent;
use App\Models\Examora\SourceDocument;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * What a given user is allowed to do, and how much of it is left.
 *
 * One place resolves a user's tier; everything else asks this. That matters
 * because the failure mode of scattered subscription checks is the one this
 * codebase already had — features shipped ungated because the check was
 * somebody's later job, and nobody noticed until payments went live.
 */
class Entitlements
{
    private ?array $tier = null;

    public function __construct(private readonly ?User $user) {}

    public static function for(?User $user): self
    {
        return new self($user);
    }

    public function tierKey(): string
    {
        if (! $this->user) {
            return config('examora.fallback_tier', 'free');
        }

        if (! $this->user->hasPremiumAccess()) {
            return config('examora.fallback_tier', 'free');
        }

        // On a trial, or subscribed to a plan whose name we do not recognise:
        // grant premium. A paying customer must never be worse off because a
        // plan was renamed in the admin.
        $plan = $this->user->subscription()->exists() ? $this->user->plan() : null;
        $slug = $plan?->name ? Str::slug($plan->name) : null;

        return config("examora.plan_tiers.{$slug}") ?? 'premium';
    }

    public function tier(): array
    {
        return $this->tier ??= config('examora.tiers.'.$this->tierKey(), []);
    }

    public function label(): string
    {
        return $this->tier()['label'] ?? ucfirst($this->tierKey());
    }

    /** @return array<int, string> */
    public function features(): array
    {
        return $this->tier()['features'] ?? [];
    }

    public function allows(string $feature): bool
    {
        return in_array($feature, $this->features(), true);
    }

    /** Null means unlimited. */
    public function limit(string $key): ?int
    {
        $limits = $this->tier()['limits'] ?? [];

        return array_key_exists($key, $limits) ? $limits[$key] : 0;
    }

    // ---------------------------------------------------------------------
    // Usage against limits
    // ---------------------------------------------------------------------

    public function lessonsUsedToday(): int
    {
        if (! $this->user) {
            return 0;
        }

        return LearningEvent::where('user_id', $this->user->id)
            ->where('type', 'lesson_completed')
            ->whereDate('occurred_at', now()->toDateString())
            ->count();
    }

    public function lessonsRemaining(): ?int
    {
        $limit = $this->limit('lessons_per_day');

        return $limit === null ? null : max(0, $limit - $this->lessonsUsedToday());
    }

    public function documentsUsedThisMonth(): int
    {
        if (! $this->user) {
            return 0;
        }

        return SourceDocument::where('user_id', $this->user->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    public function documentsRemaining(): ?int
    {
        $limit = $this->limit('documents_per_month');

        return $limit === null ? null : max(0, $limit - $this->documentsUsedThisMonth());
    }

    /**
     * Everything the client needs to render locks *before* making a request.
     *
     * A student should see a padlock and a price, not a feature that appears to
     * work and then fails. Surprise errors are how a paywall loses a customer
     * who was willing to pay.
     */
    public function toArray(): array
    {
        $lessonLimit = $this->limit('lessons_per_day');
        $documentLimit = $this->limit('documents_per_month');

        return [
            'tier' => $this->tierKey(),
            'label' => $this->label(),
            'is_premium' => $this->tierKey() !== config('examora.fallback_tier', 'free'),
            'on_trial' => (bool) $this->user?->onTrial(),
            'upgrade_url' => config('examora.upgrade_url'),

            // A map rather than a list, so the client can look a feature up
            // directly instead of scanning.
            'features' => collect(array_keys(Feature::LABELS))
                ->mapWithKeys(fn (string $f) => [$f => $this->allows($f)])
                ->all(),

            'limits' => [
                'lessons_per_day' => [
                    'limit' => $lessonLimit,
                    'used' => $this->lessonsUsedToday(),
                    'remaining' => $this->lessonsRemaining(),
                    'resets_at' => now()->addDay()->startOfDay()->toIso8601String(),
                ],
                'documents_per_month' => [
                    'limit' => $documentLimit,
                    'used' => $this->documentsUsedThisMonth(),
                    'remaining' => $this->documentsRemaining(),
                    'resets_at' => now()->addMonth()->startOfMonth()->toIso8601String(),
                ],
            ],
        ];
    }
}
