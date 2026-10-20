<?php

use App\Models\Examora\ExamEnrollment;
use App\Models\Examora\Question;
use App\Models\Examora\StudyPlanItem;
use App\Models\Examora\TopicMastery;
use App\Models\Examora\TopicStatistic;
use App\Services\Learning\DailyPlanner;
use App\Services\Learning\RevisionScheduler;
use Tests\Examora;

/**
 * The planner decides what a student does with the only hour they have. Its
 * priority order and its days-left filter are the difference between a coach
 * and a content library — and neither was asserted anywhere.
 */
beforeEach(function () {
    $this->exam = Examora::exam();
    $this->subject = Examora::subject($this->exam);
    $this->user = Examora::user();
    $this->planner = app(DailyPlanner::class);
});

/** A topic with a given star rating and importance. */
function scoredTopic(string $name, int $stars, float $importance, int $minutes = 5)
{
    $topic = Examora::topic(test()->subject, $name);

    TopicStatistic::create([
        'exam_id' => test()->exam->id,
        'topic_id' => $topic->id,
        'question_count' => $stars * 2,
        'probability_stars' => $stars,
        'importance_score' => $importance,
        'estimated_minutes' => $minutes,
    ]);

    return $topic;
}

function enrol(?int $daysLeft, int $minutes = 30): ExamEnrollment
{
    return ExamEnrollment::create([
        'user_id' => test()->user->id,
        'exam_id' => test()->exam->id,
        'target_date' => $daysLeft === null ? null : now()->addDays($daysLeft),
        'daily_minutes_goal' => $minutes,
        'is_active' => true,
        'started_at' => now(),
    ]);
}

it('offers the highest-probability chapters first', function () {
    scoredTopic('Low value', 2, 20.0);
    $top = scoredTopic('Liberation War', 5, 90.0);
    scoredTopic('Middling', 3, 50.0);

    $plan = $this->planner->planFor($this->user, enrol(90));

    expect($plan->items->first()->topic_id)->toBe($top->id);
});

it('hard-filters to five and four star chapters when the exam is close', function () {
    scoredTopic('Five star', 5, 90.0);
    scoredTopic('Four star', 4, 70.0);
    scoredTopic('Three star', 3, 50.0);
    scoredTopic('One star', 1, 10.0);

    // Ten days out. Covering three-star material now is a worse use of the time
    // than securing the five-star material.
    $plan = $this->planner->planFor($this->user, enrol(10));

    $stars = $plan->items
        ->where('type', StudyPlanItem::TYPE_LESSON)
        ->map(fn ($i) => $i->payload['probability_stars'])
        ->all();

    expect($stars)->not->toBeEmpty()
        ->and(min($stars))->toBeGreaterThanOrEqual(4);
});

it('relaxes the filter when there is time to spare', function () {
    scoredTopic('Five star', 5, 90.0);
    scoredTopic('One star', 1, 10.0);

    $plan = $this->planner->planFor($this->user, enrol(200), force: true);

    $stars = $plan->items
        ->where('type', StudyPlanItem::TYPE_LESSON)
        ->map(fn ($i) => $i->payload['probability_stars'])
        ->all();

    // With 200 days there is room for everything.
    expect(min($stars))->toBe(1);
});

it('puts due revision ahead of new material', function () {
    $topic = scoredTopic('Liberation War', 5, 90.0);

    $question = Question::withAllOwners()->create([
        'exam_id' => $this->exam->id, 'topic_id' => $topic->id,
        'origin' => Question::ORIGIN_PAST, 'stem' => 'Q?',
        'options' => ['a', 'b', 'c', 'd'], 'correct_index' => 0,
        'difficulty' => 'medium', 'is_active' => true,
    ]);

    $item = app(RevisionScheduler::class)->record($this->user, $question, true, 3000);
    $item->update(['due_at' => now()->subDay()]);

    $plan = $this->planner->planFor($this->user, enrol(90));

    // Forgetting what you already learned is the most expensive thing a
    // candidate can do, so revision is always first.
    expect($plan->items->first()->type)->toBe(StudyPlanItem::TYPE_REVISION)
        ->and($plan->items->first()->payload['due_count'])->toBe(1);
});

it('does not schedule revision when nothing is due', function () {
    scoredTopic('Liberation War', 5, 90.0);

    $plan = $this->planner->planFor($this->user, enrol(90));

    expect($plan->items->where('type', StudyPlanItem::TYPE_REVISION))->toBeEmpty();
});

it('stays inside the daily minute budget', function () {
    foreach (range(1, 8) as $i) {
        scoredTopic("Topic {$i}", 5, 90 - $i, minutes: 10);
    }

    $plan = $this->planner->planFor($this->user, enrol(90, minutes: 25));

    expect($plan->items->sum('estimated_minutes'))->toBeLessThanOrEqual(25);
});

it('skips chapters the student has already finished', function () {
    $done = scoredTopic('Already done', 5, 95.0);
    $todo = scoredTopic('Still to do', 5, 90.0);

    TopicMastery::create([
        'user_id' => $this->user->id, 'exam_id' => $this->exam->id,
        'topic_id' => $done->id, 'lesson_completed' => true, 'lesson_completed_at' => now(),
    ]);

    $plan = $this->planner->planFor($this->user, enrol(90));

    $topicIds = $plan->items->pluck('topic_id')->filter()->all();

    expect($topicIds)->toContain($todo->id)
        ->and($topicIds)->not->toContain($done->id);
});

it('schedules practice on a weak but important chapter', function () {
    $weak = scoredTopic('Weak but important', 5, 95.0);
    TopicMastery::create([
        'user_id' => $this->user->id, 'exam_id' => $this->exam->id,
        'topic_id' => $weak->id, 'attempts' => 10, 'correct' => 3,
        'accuracy' => 0.3, 'mastery' => 0.3, 'lesson_completed' => true,
    ]);

    $plan = $this->planner->planFor($this->user, enrol(90, minutes: 60));

    $practice = $plan->items->firstWhere('type', StudyPlanItem::TYPE_PRACTICE);

    expect($practice)->not->toBeNull()
        ->and($practice->topic_id)->toBe($weak->id)
        ->and($practice->reason)->toContain('30%');
});

it('adds a mock only when the exam is near', function () {
    scoredTopic('Liberation War', 5, 90.0);

    $far = $this->planner->planFor($this->user, enrol(90, minutes: 90));
    expect($far->items->where('type', StudyPlanItem::TYPE_MOCK))->toBeEmpty();

    ExamEnrollment::where('user_id', $this->user->id)->delete();

    $near = $this->planner->planFor($this->user, enrol(10, minutes: 90), force: true);
    expect($near->items->where('type', StudyPlanItem::TYPE_MOCK))->not->toBeEmpty();
});

it('gives every item a reason a student can argue with', function () {
    scoredTopic('Liberation War', 5, 90.0);

    $plan = $this->planner->planFor($this->user, enrol(90));

    // A plan a student does not trust is a plan they will not follow.
    foreach ($plan->items as $item) {
        expect($item->reason)->not->toBeEmpty();
    }

    expect($plan->items->first()->reason)->toContain('appeared before');
});

it('reuses today plan rather than reshuffling it on every visit', function () {
    scoredTopic('Liberation War', 5, 90.0);
    $enrollment = enrol(90);

    $first = $this->planner->planFor($this->user, $enrollment);
    $second = $this->planner->planFor($this->user, $enrollment);

    // A plan that changes each time you open the app is not a plan.
    expect($second->id)->toBe($first->id)
        ->and($second->items->pluck('id')->all())->toBe($first->items->pluck('id')->all());
});

it('rebuilds the plan when forced', function () {
    scoredTopic('Liberation War', 5, 90.0);
    $enrollment = enrol(90);

    $first = $this->planner->planFor($this->user, $enrollment);
    $rebuilt = $this->planner->planFor($this->user, $enrollment, force: true);

    expect($rebuilt->id)->toBe($first->id)
        ->and($rebuilt->items->pluck('id')->all())->not->toBe($first->items->pluck('id')->all());
});

it('counts completed minutes towards the day', function () {
    scoredTopic('Liberation War', 5, 90.0);

    $plan = $this->planner->planFor($this->user, enrol(90));
    $item = $plan->items->first();

    $this->planner->completeItem($item);

    expect($item->fresh()->status)->toBe('completed')
        ->and($plan->fresh()->completed_minutes)->toBe($item->estimated_minutes);
});

it('does not double-count an item completed twice', function () {
    scoredTopic('Liberation War', 5, 90.0);

    $plan = $this->planner->planFor($this->user, enrol(90));
    $item = $plan->items->first();

    $this->planner->completeItem($item);
    $this->planner->completeItem($item->fresh());

    expect($plan->fresh()->completed_minutes)->toBe($item->estimated_minutes);
});
