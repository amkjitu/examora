<?php

use App\Models\Examora\MemoryItem;
use App\Models\Examora\Question;
use App\Services\Learning\RevisionScheduler;
use Tests\Examora;

beforeEach(function () {
    $this->scheduler = app(RevisionScheduler::class);
});

it('infers higher quality from fast confident correct answers', function () {
    $scheduler = $this->scheduler;

    expect($scheduler->quality(true, 3_000, 4))->toBe(5)
        ->and($scheduler->quality(true, 15_000, null))->toBe(4)
        ->and($scheduler->quality(true, 45_000, 1))->toBe(3)
        ->and($scheduler->quality(false, 5_000, 4))->toBe(1)
        ->and($scheduler->quality(false, 5_000, null))->toBe(2);
});

it('graduates an item to the 1 / 6 / interval*ease curve', function () {
    $topic = Examora::populatedTopic();
    $user = Examora::user();

    $question = Question::create([
        'exam_id' => $topic->subject->exam_id,
        'topic_id' => $topic->id,
        'origin' => 'past',
        'stem' => 'Q',
        'options' => ['a', 'b', 'c', 'd'],
        'correct_index' => 0,
        'difficulty' => 'medium',
    ]);

    $scheduler = app(RevisionScheduler::class);

    // Two learning steps first — an item met once should not vanish for a day.
    $item = $scheduler->record($user, $question, true, 3_000);
    expect($item->state)->toBe(MemoryItem::STATE_LEARNING)
        ->and($item->interval_days)->toBe(0);

    $item = $scheduler->record($user, $question, true, 3_000);
    expect($item->state)->toBe(MemoryItem::STATE_LEARNING);

    // Third correct answer graduates it.
    $item = $scheduler->record($user, $question, true, 3_000);
    expect($item->state)->toBe(MemoryItem::STATE_REVIEW)
        ->and($item->interval_days)->toBe(1);

    $item = $scheduler->record($user, $question, true, 3_000);
    expect($item->interval_days)->toBe(6);

    // Then interval * ease. Ease is updated first, so the new interval is
    // derived from the post-update factor.
    $item = $scheduler->record($user, $question, true, 3_000);
    expect($item->interval_days)->toBe((int) round(6 * $item->ease_factor))
        ->and($item->interval_days)->toBeGreaterThan(6);
});

it('penalises ease and resets the interval on a lapse, with a floor', function () {
    $topic = Examora::populatedTopic();
    $user = Examora::user();

    $question = Question::create([
        'exam_id' => $topic->subject->exam_id,
        'topic_id' => $topic->id,
        'origin' => 'past',
        'stem' => 'Q',
        'options' => ['a', 'b', 'c', 'd'],
        'correct_index' => 0,
        'difficulty' => 'medium',
    ]);

    $scheduler = app(RevisionScheduler::class);

    for ($i = 0; $i < 4; $i++) {
        $scheduler->record($user, $question, true, 3_000);
    }

    $item = $scheduler->record($user, $question, false);

    expect($item->state)->toBe(MemoryItem::STATE_RELEARNING)
        ->and($item->interval_days)->toBe(0)
        ->and($item->lapses)->toBe(1)
        ->and($item->due_at->isBefore(now()->addMinutes(5)))->toBeTrue();

    // Ease can never fall below the 1.30 floor no matter how many lapses.
    for ($i = 0; $i < 30; $i++) {
        $item = $scheduler->record($user, $question, false);
    }

    expect($item->ease_factor)->toBeGreaterThanOrEqual(1.30);
});

it('reports retrievability decaying over time', function () {
    $item = new MemoryItem([
        'interval_days' => 10,
        'last_reviewed_at' => now()->subDays(10),
    ]);

    $scheduler = app(RevisionScheduler::class);

    expect($scheduler->retrievability($item))->toBeLessThan(0.4)
        ->and($scheduler->retrievability($item))->toBeGreaterThan(0.3);

    $fresh = new MemoryItem(['interval_days' => 10, 'last_reviewed_at' => now()]);
    expect($scheduler->retrievability($fresh))->toBe(1.0);
});
