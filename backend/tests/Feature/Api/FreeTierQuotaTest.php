<?php

use App\Models\Examora\LearningEvent;
use App\Services\Knowledge\Indexer;
use App\Services\Learning\TopicImportanceScorer;
use Tests\Examora;
use Tymon\JWTAuth\Facades\JWTAuth;

beforeEach(function () {
    // The allowance lives in the tier definition now, not a standalone key, so
    // pricing policy sits in one readable place.
    config(['examora.tiers.free.limits.lessons_per_day' => 2]);

    $this->exam = Examora::exam();
    $this->topic = Examora::populatedTopic($this->exam);

    app(Indexer::class)->indexPastQuestions($this->exam->id);
    app(TopicImportanceScorer::class)->recomputeExam($this->exam);

    $this->user = Examora::user();
    $this->headers = ['Authorization' => 'Bearer '.JWTAuth::fromUser($this->user)];
});

it('meters free users rather than blocking them outright', function () {
    $this->getJson("/api/v1/topics/{$this->topic->id}/lesson", $this->headers)->assertOk();

    // Two lessons already completed today exhausts the allowance.
    LearningEvent::insert([
        ['user_id' => $this->user->id, 'exam_id' => $this->exam->id, 'type' => 'lesson_completed', 'xp' => 25, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ['user_id' => $this->user->id, 'exam_id' => $this->exam->id, 'type' => 'lesson_completed', 'xp' => 25, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->getJson("/api/v1/topics/{$this->topic->id}/lesson", $this->headers)
        ->assertStatus(402)
        ->assertJsonPath('code', 'lesson_quota_exceeded')
        ->assertJsonPath('quota.limit', 2);
});

it('reports remaining quota on the profile endpoint', function () {
    $this->getJson('/api/v1/me', $this->headers)
        ->assertOk()
        ->assertJsonPath('data.quota.free_lessons_per_day', 2)
        ->assertJsonPath('data.quota.remaining', 2)
        ->assertJsonPath('data.subscription.is_premium', false);
});
