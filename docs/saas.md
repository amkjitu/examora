# SaaS: tiers, entitlements and gating

## What is real, and what is not

| | State |
|---|---|
| Tier definitions, entitlements, feature gating | **Working, tested** |
| Free-tier metering | **Working** |
| Wave subscriptions, plans, roles, billing pages | Installed and functional |
| Stripe / Paddle credentials | **Placeholders** — `pk_test_...`, `sk_test_...` |
| Plan price IDs | **Dummy** — `dummy_basic_monthly_id`, no real Stripe products |
| Checkout, webhooks | **Never exercised.** Zero subscriptions created through payment |
| bKash / Nagad | **Absent** — and for Bangladesh these are the methods that matter |

Gating is finished and enforced. **Taking money is not.** See
[`roadmap.md`](roadmap.md).

---

## How gating works

Nothing asks "is this user subscribed?". It asks whether they are entitled to a
**named feature**. That keeps commercial policy in one readable file instead of
scattered through controllers — and a pricing change becomes a config edit.

```
config/examora.php  →  tiers, features, limits, plan→tier mapping
App\Support\Feature →  the feature names
App\Support\Entitlements → resolves a user's tier, features and remaining limits
RequiresFeature middleware → 402 naming the feature and where to upgrade
```

### The split

| Feature | Free | Premium | Why |
|---|---|---|---|
| Chapter rankings (★ probability) | ✅ | ✅ | The proof the premise works. Hiding it hides the moat |
| Lessons | 3/day | Unlimited | Metered, not walled |
| Practice | ✅ | ✅ | No marginal cost |
| **Revision** | ✅ | ✅ | **Never gated.** Forgetting is the costliest thing a candidate can do |
| Daily plan, readiness | ✅ | ✅ | No marginal cost |
| Topic forecast | ✅ | ✅ | Marketing surface — the clearest argument for paying |
| **Repeat candidates** | ❌ | ✅ | The highest-value output: questions already reused verbatim |
| **Mock exams** | ❌ | ✅ | The exam simulation |
| **AI tutor** | ❌ | ✅ | Real per-call LLM cost |
| **Document upload** | ❌ | ✅ | Real per-page LLM cost |

Two principles decided this:

**The free tier has to be genuinely useful.** A candidate who cannot see the
product work will not pay for it. Free includes the ranked chapter list, real
lessons, and unlimited practice and revision.

**What costs money to serve is what gets gated.** The tutor and ingestion carry
real per-call LLM spend; mock exams and repeat candidates are the highest-value
output. Everything else is marginal-cost-zero and stays open.

### Deliberate carve-outs

- **Reading your own uploads is never gated**, only creating new ones. Locking
  someone out of their own data on downgrade would be indefensible.
- **Finishing a quiz is never gated**, only starting a mock. A plan lapsing
  mid-exam must not lose the paper.
- **An unrecognised plan name grants premium.** A paying customer must never be
  worse off because a plan was renamed in the admin.

---

## Adding a gate

```php
Route::post('something', [Controller::class, 'method'])
    ->middleware('feature:mock_exam');
```

Where one endpoint serves several things and only some are premium — as with
`POST /quizzes`, which handles practice, revision and mocks — gate inside the
controller instead. `QuizController::start` is the example.

The 402 body is deliberately specific:

```json
{
  "message": "Mock exams are not included in your Free plan.",
  "code": "feature_not_included",
  "feature": "mock_exam",
  "feature_label": "Mock exams",
  "current_tier": "free",
  "upgrade_url": "/settings/subscription"
}
```

"Payment Required" converts nobody. A client needs to be able to render
something useful.

---

## The client never has to guess

`GET /api/v1/me` returns the full entitlement map, so the SPA renders a padlock
*before* making a request:

```json
{
  "entitlements": {
    "tier": "free",
    "is_premium": false,
    "upgrade_url": "/settings/subscription",
    "features": { "mock_exam": false, "revision": true, "...": "..." },
    "limits": {
      "lessons_per_day": { "limit": 3, "used": 1, "remaining": 2, "resets_at": "..." },
      "documents_per_month": { "limit": 0, "used": 0, "remaining": 0, "resets_at": "..." }
    }
  }
}
```

Unlimited is `null`, never a large number.

In the SPA, `useEntitlements()` reads this and `<Locked>` / `<Gate>` render the
padlock. **Locks default to closed while loading**, so nothing flashes as
available and then disappears. A feature that looks available and then errors is
how a paywall loses someone who was willing to pay.

---

## Changing the pricing

Edit `config/examora.php`. To move mock exams to free, delete one line:

```php
'free' => [
    'features' => [
        Feature::CHAPTER_RANKINGS,
        Feature::MOCK_EXAM,   // ← added
        ...
    ],
    'limits' => ['lessons_per_day' => 5],  // ← raised
],
```

No controller changes. `plan_tiers` maps Wave plan names (slugified) onto tiers,
so introducing a mid-tier plan is a config entry plus a new tier block.

---

## Testing it

`tests/Feature/Api/EntitlementsTest.php` — 18 tests covering every gate in both
directions, the limits, and the entitlement payload.

To make a user premium locally without touching Stripe:

```bash
php artisan tinker --execute="
\$u = App\Models\User::where('email','admin@admin.com')->first();
Wave\Subscription::create([
  'billable_id' => \$u->id, 'billable_type' => 'user',
  'plan_id' => Wave\Plan::first()->id,
  'vendor_transaction_id' => 'local_'.rand(), 'vendor_customer_id' => 'cus',
  'vendor_user_id' => (string) \$u->id, 'vendor_slug' => 'stripe',
  'status' => 'active', 'plan' => 'Premium', 'cycle' => 'month',
]);
Illuminate\Support\Facades\Cache::flush();"
```

Two things that will catch you out: Wave keys subscriptions on **`billable_id` +
`billable_type`**, not `user_id`; and `subscriber()` **caches for five minutes**,
so flush the cache after creating one.

To go back to free, delete the subscription and flush again.

---

## Before taking money

1. Real Stripe products and price IDs on each plan (replacing `dummy_*`)
2. Live keys in `.env`
3. Webhook endpoint verified — `/webhook/stripe` is already CSRF-exempt
4. A bKash/Nagad driver. Neither is supported by Cashier, so this is a custom
   integration and probably the largest single piece
5. Pricing appropriate to the market — the seeded $5/$8/$12 are Wave's defaults,
   not a considered decision for Bangladesh
6. Decide the trial. `users.trial_ends_at` exists and `onTrial()` grants premium,
   but nothing currently sets it
