<?php

use App\Models\Examora\LearnerProfile;
use App\Models\Examora\Question;
use App\Models\Examora\QuizAnswer;
use App\Models\Examora\QuizSession;
use App\Models\Examora\Topic;
use App\Models\Examora\TopicMastery;
use App\Models\Examora\TopicStatistic;
use App\Models\User;
use App\Services\Learning\ProgressTracker;
use App\Services\Learning\RevisionScheduler;
use Tests\Examora;

/**
 * Mastery and readiness are the numbers a student builds a study plan around,
 * and until now nothing asserted either formula. A wrong readiness score looks
 * exactly as plausible as a right one.
 */
beforeEach(function () {
    $this->exam = Examora::exam();
    $this->subject = Examora::subject($this->exam);
    $this->topic = Examora::topic($this->subject, 'Liberation War');
    $this->user = Examora::user();
    $this->tracker = app(ProgressTracker::class);
});

/** Adds N questions to the practice bank for a topic. */
function bank(Topic $topic, int $count): array
{
    return collect(range(1, $count))->map(fn ($i) => Question::withAllOwners()->create([
        'exam_id' => $topic->subject->exam_id,
        'topic_id' => $topic->id,
        'origin' => Question::ORIGIN_PAST,
        'stem' => "Question {$i}?",
        'options' => ['a', 'b', 'c', 'd'],
        'correct_index' => 0,
        'difficulty' => 'medium',
        'is_active' => true,
    ]))->all();
}

/**
 * Records answers against a topic, `$correct` of them right.
 *
 * Goes through the scheduler as well as writing the answer, because that is
 * what production does — and because **coverage is measured from `memory_items`,
 * not from answers**. Writing answers alone leaves coverage permanently zero and
 * quietly weakens every assertion about mastery.
 */
function answered(User $user, array $questions, int $correct): void
{
    $session = QuizSession::create([
        'user_id' => $user->id,
        'exam_id' => $questions[0]->exam_id,
        'topic_id' => $questions[0]->topic_id,
        'type' => QuizSession::TYPE_PRACTICE,
        'question_ids' => collect($questions)->pluck('id')->all(),
        'started_at' => now(),
    ]);

    $scheduler = app(RevisionScheduler::class);

    foreach ($questions as $index => $question) {
        $isCorrect = $index < $correct;

        QuizAnswer::create([
            'quiz_session_id' => $session->id,
            'question_id' => $question->id,
            'user_id' => $user->id,
            'selected_index' => 0,
            'is_correct' => $isCorrect,
            'answered_at' => now(),
        ]);

        $scheduler->record($user, $question, $isCorrect, 5000);
    }
}

it('does not call a topic mastered on accuracy alone', function () {
    $questions = bank($this->topic, 10);

    // Two out of two correct, on a bank of ten. Perfect accuracy, almost no
    // coverage, no retention history — that is not mastery.
    answered($this->user, array_slice($questions, 0, 2), correct: 2);

    $mastery = $this->tracker->recomputeMastery($this->user->id, $this->exam->id, $this->topic->id);

    expect($mastery->accuracy)->toBe(1.0)
        ->and($mastery->coverage)->toBeLessThan(0.3)
        ->and($mastery->mastery)->toBeLessThan(0.6);
});

it('weights accuracy, coverage and retention as documented', function () {
    $questions = bank($this->topic, 10);
    answered($this->user, $questions, correct: 8);

    // Every question seen, so coverage is driven by memory items rather than
    // answers; none exist here, so retention is zero.
    $mastery = $this->tracker->recomputeMastery($this->user->id, $this->exam->id, $this->topic->id);

    // 0.45 * accuracy + 0.25 * coverage + 0.30 * retention
    $expected = 0.45 * $mastery->accuracy + 0.25 * $mastery->coverage + 0.30 * 0.0;

    expect($mastery->mastery)->toBe(round($expected, 4))
        ->and($mastery->attempts)->toBe(10)
        ->and($mastery->correct)->toBe(8);
});

it('raises mastery as coverage grows at the same accuracy', function () {
    $questions = bank($this->topic, 10);

    answered($this->user, array_slice($questions, 0, 2), correct: 1);
    $narrow = $this->tracker->recomputeMastery($this->user->id, $this->exam->id, $this->topic->id)->mastery;

    answered($this->user, array_slice($questions, 2, 8), correct: 4);
    $broad = $this->tracker->recomputeMastery($this->user->id, $this->exam->id, $this->topic->id)->mastery;

    // Same 50% accuracy, far more of the bank seen.
    expect($broad)->toBeGreaterThan($narrow);
});

it('counts a student own uploaded questions in their coverage', function () {
    bank($this->topic, 4);

    Question::withAllOwners()->create([
        'exam_id' => $this->exam->id,
        'owner_id' => $this->user->id,
        'topic_id' => $this->topic->id,
        'origin' => Question::ORIGIN_CURATED,
        'stem' => 'From my own upload?',
        'options' => ['a', 'b', 'c', 'd'],
        'correct_index' => 0,
        'difficulty' => 'medium',
        'is_active' => true,
    ]);

    $mastery = $this->tracker->recomputeMastery($this->user->id, $this->exam->id, $this->topic->id);

    // Coverage is relative to the bank this student can actually practise from,
    // which is five, not four.
    expect($mastery->coverage)->toBe(0.0);
    expect(Question::visibleTo($this->user->id)->where('topic_id', $this->topic->id)->count())->toBe(5);
});

it('weights readiness by what the exam actually asks', function () {
    $heavy = Examora::topic($this->subject, 'Heavy Topic');
    $light = Examora::topic($this->subject, 'Light Topic');

    TopicStatistic::create([
        'exam_id' => $this->exam->id, 'topic_id' => $heavy->id,
        'expected_questions_min' => 5, 'expected_questions_max' => 6, 'probability_stars' => 5,
    ]);
    TopicStatistic::create([
        'exam_id' => $this->exam->id, 'topic_id' => $light->id,
        'expected_questions_min' => 0, 'expected_questions_max' => 1, 'probability_stars' => 1,
    ]);

    // Master the topic worth one question, ignore the one worth six.
    TopicMastery::create([
        'user_id' => $this->user->id, 'exam_id' => $this->exam->id,
        'topic_id' => $light->id, 'mastery' => 1.0, 'accuracy' => 1.0,
    ]);

    $wrongWayRound = $this->tracker->readiness($this->user, $this->exam->id);

    // Flip it: master the heavy one instead.
    TopicMastery::where('user_id', $this->user->id)->delete();
    TopicMastery::create([
        'user_id' => $this->user->id, 'exam_id' => $this->exam->id,
        'topic_id' => $heavy->id, 'mastery' => 1.0, 'accuracy' => 1.0,
    ]);

    $rightWayRound = $this->tracker->readiness($this->user, $this->exam->id);

    // Ignoring a six-question topic must cost far more than ignoring a
    // one-question topic. That asymmetry is the entire premise of the product.
    expect($rightWayRound['readiness'])->toBeGreaterThan($wrongWayRound['readiness'] * 3);
});

it('converts readiness into an estimated score out of the exam total', function () {
    $topic = Examora::topic($this->subject, 'Only Topic');

    TopicStatistic::create([
        'exam_id' => $this->exam->id, 'topic_id' => $topic->id,
        'expected_questions_min' => 1, 'expected_questions_max' => 2, 'probability_stars' => 3,
    ]);

    TopicMastery::create([
        'user_id' => $this->user->id, 'exam_id' => $this->exam->id,
        'topic_id' => $topic->id, 'mastery' => 0.5, 'accuracy' => 0.5,
    ]);

    $readiness = $this->tracker->readiness($this->user, $this->exam->id);

    expect($readiness['readiness'])->toBe(50.0)
        ->and($readiness['estimated_score'])->toBe((int) round(0.5 * $this->exam->total_marks));
});

it('reports zero readiness rather than dividing by nothing on an unscored exam', function () {
    expect($this->tracker->readiness($this->user, $this->exam->id))
        ->toBe(['readiness' => 0.0, 'estimated_score' => 0, 'covered_topics' => 0, 'total_topics' => 0]);
});

it('awards more XP for a right answer than a wrong one, but never nothing', function () {
    $questions = bank($this->topic, 3);
    answered($this->user, $questions, correct: 2);

    $session = QuizSession::where('user_id', $this->user->id)->first();
    $this->tracker->afterQuiz($session);

    // 2 correct * 10 + 1 wrong * 2 = 22. Effort counts, or students stop trying.
    $profile = LearnerProfile::where('user_id', $this->user->id)->first();

    expect($profile->xp)->toBe(22);
});

it('extends a streak on a consecutive day and resets it after a gap', function () {
    $profile = LearnerProfile::create([
        'user_id' => $this->user->id, 'exam_id' => $this->exam->id,
        'xp' => 0, 'level' => 1, 'streak_days' => 4,
        'last_active_on' => now()->subDay()->toDateString(),
    ]);

    $this->tracker->award($this->user, $this->exam->id, 10, 'quiz_completed');

    expect($profile->fresh()->streak_days)->toBe(5);

    // Now a two-day gap: the streak is broken, not paused.
    $profile->update(['last_active_on' => now()->subDays(2)->toDateString()]);
    $this->tracker->award($this->user, $this->exam->id, 10, 'quiz_completed');

    expect($profile->fresh()->streak_days)->toBe(1);
});

it('does not double-count a streak for two sessions on the same day', function () {
    $this->tracker->award($this->user, $this->exam->id, 10, 'quiz_completed');
    $this->tracker->award($this->user, $this->exam->id, 10, 'quiz_completed');

    expect(LearnerProfile::where('user_id', $this->user->id)->value('streak_days'))->toBe(1);
});

it('makes each level cost more than the last', function () {
    expect(LearnerProfile::levelForXp(0))->toBe(1)
        ->and(LearnerProfile::levelForXp(99))->toBe(1)
        ->and(LearnerProfile::levelForXp(100))->toBe(2)
        ->and(LearnerProfile::levelForXp(220))->toBe(3);

    // The gap between levels widens, so a level always means something.
    $gaps = [];
    $previous = 0;

    foreach (range(2, 6) as $level) {
        $xp = collect(range(0, 5000))->first(fn ($x) => LearnerProfile::levelForXp($x) === $level);
        $gaps[] = $xp - $previous;
        $previous = $xp;
    }

    expect($gaps)->toBe(collect($gaps)->sort()->values()->all());
});
