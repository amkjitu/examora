<?php

use App\Models\Examora\LearningEvent;
use App\Models\Examora\TopicMastery;
use App\Services\Knowledge\Indexer;
use App\Services\Learning\LessonGenerator;
use App\Services\Learning\TopicImportanceScorer;
use App\Services\Prediction\PredictionEngine;
use App\Support\Entitlements;
use App\Support\Feature;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Examora;
use Tymon\JWTAuth\Facades\JWTAuth;
use Wave\Plan;
use Wave\Subscription;

/**
 * Every premium feature shipped ungated once already — built, then never
 * paywalled, because the check was always somebody's later job. These tests
 * exist so that cannot happen silently again.
 */
beforeEach(function () {
    $this->exam = Examora::exam(['slug' => 'bcs-preliminary']);
    $this->topic = Examora::populatedTopic($this->exam);

    app(Indexer::class)->indexPastQuestions($this->exam->id);
    app(TopicImportanceScorer::class)->recomputeExam($this->exam->fresh());
    app(PredictionEngine::class)->forecast($this->exam->fresh());

    // A practice bank to draw from, so a 422 in these tests means "blocked",
    // never "nothing to serve".
    app(LessonGenerator::class)->generate($this->topic->fresh(['subject.exam']));

    $this->free = Examora::user();
    $this->freeHeaders = ['Authorization' => 'Bearer '.JWTAuth::fromUser($this->free)];
});

/**
 * A user Wave considers subscribed, without touching Stripe.
 *
 * Two things to get right, both easy to miss: Wave keys subscriptions on
 * `billable_id` + `billable_type`, not `user_id`; and `subscriber()` caches its
 * answer for five minutes, so a subscription created after any earlier check
 * would otherwise read as absent.
 */
function subscriber(?string $planName = null): array
{
    $user = Examora::user();
    $plan = $planName ? Plan::where('name', $planName)->first() : Plan::first();

    Subscription::create([
        'billable_id' => $user->id,
        'billable_type' => 'user',
        'plan_id' => $plan?->id ?? 1,
        'vendor_transaction_id' => 'test_'.uniqid(),
        'vendor_customer_id' => 'cus_test',
        'vendor_user_id' => (string) $user->id,
        'vendor_slug' => 'stripe',
        'status' => 'active',
        'plan' => $plan?->name ?? 'Premium',
        // Enum: month | year | onetime. Not "monthly".
        'cycle' => 'month',
    ]);

    Cache::flush();

    return [$user->fresh(), ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)]];
}

// ---------------------------------------------------------------------------
// The tier itself
// ---------------------------------------------------------------------------

it('puts a user with no subscription on the free tier', function () {
    $entitlements = Entitlements::for($this->free);

    expect($entitlements->tierKey())->toBe('free')
        ->and($entitlements->allows(Feature::LESSONS))->toBeTrue()
        ->and($entitlements->allows(Feature::REVISION))->toBeTrue()
        ->and($entitlements->allows(Feature::MOCK_EXAM))->toBeFalse()
        ->and($entitlements->allows(Feature::AI_TUTOR))->toBeFalse();
});

it('puts a subscriber on the premium tier', function () {
    [$user] = subscriber();

    $entitlements = Entitlements::for($user->fresh());

    expect($entitlements->tierKey())->toBe('premium')
        ->and($entitlements->allows(Feature::MOCK_EXAM))->toBeTrue()
        ->and($entitlements->allows(Feature::AI_TUTOR))->toBeTrue()
        ->and($entitlements->limit('lessons_per_day'))->toBeNull();
});

it('keeps the free tier genuinely useful rather than a demo', function () {
    $entitlements = Entitlements::for($this->free);

    // A candidate who cannot see the product work will not pay for it. The
    // ranking is the proof the premise holds, and revision is what protects
    // their marks — neither is ever walled.
    expect($entitlements->allows(Feature::CHAPTER_RANKINGS))->toBeTrue()
        ->and($entitlements->allows(Feature::PRACTICE))->toBeTrue()
        ->and($entitlements->allows(Feature::REVISION))->toBeTrue()
        ->and($entitlements->allows(Feature::DAILY_PLAN))->toBeTrue()
        ->and($entitlements->allows(Feature::FORECAST_TOPICS))->toBeTrue();
});

// ---------------------------------------------------------------------------
// The gates
// ---------------------------------------------------------------------------

it('blocks a mock exam on the free tier and names what unlocks it', function () {
    $response = $this->postJson('/api/v1/quizzes', [
        'type' => 'mock',
        'exam_id' => $this->exam->id,
        'count' => 10,
    ], $this->freeHeaders)->assertStatus(402);

    // Specific enough for a client to render something useful. "Payment
    // Required" converts nobody.
    $response->assertJsonPath('code', 'feature_not_included')
        ->assertJsonPath('feature', Feature::MOCK_EXAM)
        ->assertJsonPath('current_tier', 'free');

    expect($response->json('upgrade_url'))->not->toBeEmpty()
        ->and($response->json('message'))->toContain('Mock exams');
});

it('allows a mock exam for a subscriber', function () {
    [, $headers] = subscriber();

    $this->postJson('/api/v1/quizzes', [
        'type' => 'mock',
        'exam_id' => $this->exam->id,
        'count' => 5,
    ], $headers)->assertCreated();
});

it('leaves practice and revision open on the free tier', function () {
    // Revision has no marginal serving cost and forgetting is the costliest
    // thing a candidate can do. It must never sit behind the paywall.
    $this->postJson('/api/v1/quizzes', [
        'type' => 'practice',
        'topic_id' => $this->topic->id,
    ], $this->freeHeaders)->assertCreated();

    $this->postJson('/api/v1/quizzes', [
        'type' => 'revision',
        'exam_id' => $this->exam->id,
    ], $this->freeHeaders)->assertStatus(422); // nothing due yet — not a paywall
});

it('blocks the AI tutor on the free tier', function () {
    $this->postJson('/api/v1/tutor/ask', [
        'question' => 'Explain osmosis',
        'exam_id' => $this->exam->id,
    ], $this->freeHeaders)
        ->assertStatus(402)
        ->assertJsonPath('feature', Feature::AI_TUTOR);
});

it('allows the AI tutor for a subscriber', function () {
    [, $headers] = subscriber();

    $this->postJson('/api/v1/tutor/ask', [
        'question' => 'Explain osmosis',
        'exam_id' => $this->exam->id,
    ], $headers)->assertOk();
});

it('blocks document upload on the free tier', function () {
    Storage::fake('local');
    Queue::fake();

    $this->post('/api/v1/documents', [
        'file' => UploadedFile::fake()->create('paper.pdf', 100, 'application/pdf'),
        'exam_id' => $this->exam->id,
    ], $this->freeHeaders + ['Accept' => 'application/json'])
        ->assertStatus(402)
        ->assertJsonPath('feature', Feature::DOCUMENT_UPLOAD);

    Queue::assertNothingPushed();
});

it('still lets a free user read documents they uploaded before downgrading', function () {
    // Locking someone out of their own data on downgrade would be indefensible.
    $this->getJson('/api/v1/documents', $this->freeHeaders)->assertOk();
    $this->getJson('/api/v1/my-questions', $this->freeHeaders)->assertOk();
});

it('shows the forecast on the free tier but withholds the repeat candidates', function () {
    $free = $this->getJson("/api/v1/exams/{$this->exam->slug}/predictions", $this->freeHeaders)
        ->assertOk();

    // The ranking is the clearest argument for paying; hiding it would hide
    // the moat. The part worth memorising is premium.
    expect($free->json('data.topics'))->not->toBeEmpty()
        ->and($free->json('data.repeat_candidates'))->toBe([])
        ->and($free->json('data.repeat_candidates_locked'))->toBeTrue();
});

it('unlocks the repeat candidates for a subscriber', function () {
    // A separate test on purpose: the JWT guard is a singleton that latches onto
    // the first bearer token in a request cycle, so two tiers cannot share one.
    [, $headers] = subscriber();

    $this->getJson("/api/v1/exams/{$this->exam->slug}/predictions", $headers)
        ->assertOk()
        ->assertJsonPath('data.repeat_candidates_locked', false);
});

// ---------------------------------------------------------------------------
// Limits
// ---------------------------------------------------------------------------

it('meters lessons on the free tier from the tier definition', function () {
    config(['examora.tiers.free.limits.lessons_per_day' => 1]);

    $this->getJson("/api/v1/topics/{$this->topic->id}/lesson", $this->freeHeaders)->assertOk();

    LearningEvent::create([
        'user_id' => $this->free->id, 'exam_id' => $this->exam->id,
        'type' => 'lesson_completed', 'xp' => 25, 'occurred_at' => now(),
    ]);

    $this->getJson("/api/v1/topics/{$this->topic->id}/lesson", $this->freeHeaders)
        ->assertStatus(402)
        ->assertJsonPath('code', 'lesson_quota_exceeded')
        // A quota message that reads like a hard stop makes people leave.
        ->assertJsonPath('still_available', 'Practice and revision are unlimited on every plan.');
});

it('does not meter lessons for a subscriber', function () {
    [$user, $headers] = subscriber();

    foreach (range(1, 5) as $i) {
        LearningEvent::create([
            'user_id' => $user->id, 'exam_id' => $this->exam->id,
            'type' => 'lesson_completed', 'xp' => 25, 'occurred_at' => now(),
        ]);
    }

    $this->getJson("/api/v1/topics/{$this->topic->id}/lesson", $headers)->assertOk();

    expect(Entitlements::for($user->fresh())->lessonsRemaining())->toBeNull();
});

// ---------------------------------------------------------------------------
// What the client is told
// ---------------------------------------------------------------------------

it('gives the client a full entitlement map so it can render locks up front', function () {
    $data = $this->getJson('/api/v1/me', $this->freeHeaders)->assertOk()->json('data.entitlements');

    expect($data['tier'])->toBe('free')
        ->and($data['is_premium'])->toBeFalse()
        ->and($data['upgrade_url'])->not->toBeEmpty()
        // A map, so the client looks a feature up rather than scanning.
        ->and($data['features'][Feature::MOCK_EXAM])->toBeFalse()
        ->and($data['features'][Feature::REVISION])->toBeTrue()
        ->and($data['limits']['lessons_per_day']['limit'])->toBeGreaterThan(0)
        ->and($data['limits']['documents_per_month']['limit'])->toBe(0);
});

it('reports unlimited as null rather than a large number', function () {
    [, $headers] = subscriber();

    $limits = $this->getJson('/api/v1/me', $headers)->assertOk()->json('data.entitlements.limits');

    expect($limits['lessons_per_day']['limit'])->toBeNull()
        ->and($limits['lessons_per_day']['remaining'])->toBeNull();
});

/*
 * Regression: the public routes had no way to resolve a bearer token, so
 * $request->user() was null even for a signed-in caller. The chapter list
 * reported 0% mastery to everyone, and because the UI only renders mastery above
 * zero it looked like a feature that had never been built.
 */
it('personalises a public route when a token is present', function () {
    TopicMastery::create([
        'user_id' => $this->free->id,
        'exam_id' => $this->exam->id,
        'topic_id' => $this->topic->id,
        'attempts' => 10,
        'correct' => 8,
        'accuracy' => 0.8,
        'mastery' => 0.62,
        'coverage' => 0.5,
    ]);

    $withToken = $this->getJson("/api/v1/exams/{$this->exam->slug}/topics", $this->freeHeaders)
        ->assertOk()
        ->json('data');

    expect(collect($withToken)->firstWhere('id', $this->topic->id)['mastery'])->toBe(0.62);
});

it('stays anonymous on a public route without a token, and fails open on a bad one', function () {
    $anonymous = $this->getJson("/api/v1/exams/{$this->exam->slug}/topics")->assertOk()->json('data');

    // JSON collapses 0.0 to 0, so compare numerically rather than by type.
    expect((float) collect($anonymous)->firstWhere('id', $this->topic->id)['mastery'])->toBe(0.0);

    // "Optional" has to mean a malformed token leaves you anonymous, not rejected.
    $this->getJson("/api/v1/exams/{$this->exam->slug}/topics", [
        'Authorization' => 'Bearer not-a-real-token',
    ])->assertOk();
});

it('falls back to premium for a plan name it does not recognise', function () {
    [$user] = subscriber();

    Plan::first()->update(['name' => 'Some Renamed Plan']);

    // A paying customer must never be worse off because a plan was renamed.
    expect(Entitlements::for($user->fresh())->tierKey())->toBe('premium');
});
