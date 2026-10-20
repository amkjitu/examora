<?php

use App\Models\Examora\Question;
use App\Models\Examora\QuizAnswer;
use App\Models\Examora\QuizSession;
use App\Models\Examora\Topic;
use App\Services\Learning\MockAnalyser;
use App\Services\Learning\QuizEngine;
use Tests\Examora;

/**
 * The post-exam analysis is what a student changes their revision on the back
 * of. Its arithmetic was only ever checked end to end, which cannot tell a
 * plausible-looking wrong number from a right one.
 */
beforeEach(function () {
    $this->exam = Examora::exam([
        'total_marks' => 20,
        'duration_minutes' => 20,
        'negative_marking' => 0.50,
    ]);

    $this->bangla = Examora::subject($this->exam, 'Bangla', 35);
    $this->science = Examora::subject($this->exam, 'General Science', 15);

    $this->banglaTopic = Examora::topic($this->bangla, 'Bangla Grammar');
    $this->scienceTopic = Examora::topic($this->science, 'Human Physiology');

    $this->user = Examora::user();
    $this->analyser = app(MockAnalyser::class);
});

function question(Topic $topic, string $stem): Question
{
    return Question::withAllOwners()->create([
        'exam_id' => $topic->subject->exam_id,
        'topic_id' => $topic->id,
        'origin' => Question::ORIGIN_PAST,
        'stem' => $stem,
        'options' => ['right', 'wrong', 'also wrong', 'still wrong'],
        'correct_index' => 0,
        'explanation' => 'Because it is.',
        'difficulty' => 'medium',
        'is_active' => true,
        'meta' => ['years' => [2019, 2023]],
    ]);
}

/**
 * Builds a completed mock. `$answers` maps question index to
 * [selected_index|null, time_ms].
 */
function completedMock(array $questions, array $answers, int $durationMinutes = 10): QuizSession
{
    $session = QuizSession::create([
        'user_id' => test()->user->id,
        'exam_id' => test()->exam->id,
        'type' => QuizSession::TYPE_MOCK,
        'question_ids' => collect($questions)->pluck('id')->all(),
        'started_at' => now()->subMinutes(5),
        'meta' => [
            'mode' => QuizSession::MODE_STRICT,
            'duration_minutes' => $durationMinutes,
            'expires_at' => now()->addMinutes($durationMinutes)->toIso8601String(),
            'negative_marking' => 0.5,
            'readiness_before' => 10.0,
            'estimated_score_before' => 2,
        ],
    ]);

    foreach ($answers as $index => [$selected, $timeMs]) {
        QuizAnswer::create([
            'quiz_session_id' => $session->id,
            'question_id' => $questions[$index]->id,
            'user_id' => test()->user->id,
            'selected_index' => $selected,
            'is_correct' => $selected === 0,
            'time_ms' => $timeMs,
            'answered_at' => now(),
        ]);
    }

    return app(QuizEngine::class)->complete($session);
}

it('splits the result by subject, weakest first', function () {
    $questions = [
        question($this->banglaTopic, 'Bangla one?'),
        question($this->banglaTopic, 'Bangla two?'),
        question($this->scienceTopic, 'Science one?'),
        question($this->scienceTopic, 'Science two?'),
    ];

    // Bangla perfect, Science both wrong.
    $session = completedMock($questions, [
        0 => [0, 5000], 1 => [0, 5000],
        2 => [1, 5000], 3 => [1, 5000],
    ]);

    $subjects = collect($this->analyser->analyse($session)['subjects']);

    // Weakest first — that is what to act on.
    expect($subjects->first()['subject'])->toBe('General Science')
        ->and($subjects->last()['subject'])->toBe('Bangla');

    $science = $subjects->firstWhere('subject', 'General Science');
    $bangla = $subjects->firstWhere('subject', 'Bangla');

    expect($science['correct'])->toBe(0)
        ->and($science['wrong'])->toBe(2)
        // Two wrong at -0.5 each.
        ->and($science['marks'])->toBe(-1.0)
        ->and($bangla['marks'])->toBe(2.0)
        ->and($bangla['exam_marks'])->toBe(35);
});

it('accounts for every question exactly once across subjects', function () {
    $questions = [
        question($this->banglaTopic, 'One?'),
        question($this->scienceTopic, 'Two?'),
        question($this->scienceTopic, 'Three?'),
    ];

    $session = completedMock($questions, [0 => [0, 5000], 1 => [1, 5000]]);

    $subjects = collect($this->analyser->analyse($session)['subjects']);

    expect($subjects->sum('total'))->toBe(3)
        ->and($subjects->sum('attempted'))->toBe(2)
        ->and($subjects->sum('skipped'))->toBe(1);
});

it('does not penalise a skipped question', function () {
    $questions = [question($this->banglaTopic, 'One?'), question($this->banglaTopic, 'Two?')];

    // One right, one never answered.
    $session = completedMock($questions, [0 => [0, 5000]]);

    $summary = $this->analyser->analyse($session)['summary'];

    // Cast: PHP collapses a whole float to int through the meta round-trip.
    expect($summary['correct'])->toBe(1)
        ->and($summary['wrong'])->toBe(0)
        ->and($summary['skipped'])->toBe(1)
        ->and((float) $summary['marks'])->toBe(1.0)
        ->and((float) $summary['penalty_marks'])->toBe(0.0);
});

it('reports accuracy on what was attempted, not on the whole paper', function () {
    $questions = [
        question($this->banglaTopic, 'One?'),
        question($this->banglaTopic, 'Two?'),
        question($this->banglaTopic, 'Three?'),
        question($this->banglaTopic, 'Four?'),
    ];

    // Two attempted, one right. Two skipped.
    $session = completedMock($questions, [0 => [0, 5000], 1 => [1, 5000]]);

    $summary = $this->analyser->analyse($session)['summary'];

    // 50% of attempts, not 25% of the paper — this is the number that tells a
    // student whether guessing is costing them under negative marking.
    expect((float) $summary['accuracy_on_attempted'])->toBe(0.5);
});

it('numbers the review in the order the paper was served', function () {
    $questions = [
        question($this->banglaTopic, 'First served?'),
        question($this->banglaTopic, 'Second served?'),
    ];

    $session = completedMock($questions, [0 => [1, 5000], 1 => [0, 5000]]);

    $review = $this->analyser->analyse($session)['review'];

    expect($review[0]['position'])->toBe(1)
        ->and($review[0]['stem'])->toBe('First served?')
        ->and($review[0]['is_correct'])->toBeFalse()
        ->and($review[0]['selected_index'])->toBe(1)
        // The key and the provenance are visible now the paper is submitted.
        ->and($review[0]['correct_index'])->toBe(0)
        ->and($review[0]['explanation'])->toBe('Because it is.')
        ->and($review[0]['previously_examined'])->toBe([2019, 2023]);
});

it('marks an unanswered question as skipped rather than wrong', function () {
    $questions = [question($this->banglaTopic, 'One?'), question($this->banglaTopic, 'Two?')];

    $session = completedMock($questions, [0 => [0, 5000]]);

    $review = collect($this->analyser->analyse($session)['review']);
    $skipped = $review->firstWhere('stem', 'Two?');

    expect($skipped['skipped'])->toBeTrue()
        ->and($skipped['is_correct'])->toBeFalse()
        ->and($skipped['selected_index'])->toBeNull();
});

it('flags a question that was slow and still wrong', function () {
    $questions = collect(range(1, 5))
        ->map(fn ($i) => question($this->banglaTopic, "Question {$i}?"))
        ->all();

    // Five questions in ten minutes: a 120s budget each. One burns six minutes
    // and is still wrong; the rest are quick and right.
    $session = completedMock($questions, [
        0 => [1, 360_000],
        1 => [0, 5_000], 2 => [0, 5_000], 3 => [0, 5_000], 4 => [0, 5_000],
    ], durationMinutes: 10);

    $pacing = $this->analyser->analyse($session)['pacing'];

    expect($pacing['budget_ms'])->toBe(120_000)
        ->and($pacing['time_sinks'])->toHaveCount(1)
        ->and($pacing['time_sinks'][0]['time_ms'])->toBe(360_000);
});

it('does not flag a question that was slow but right', function () {
    $questions = collect(range(1, 5))
        ->map(fn ($i) => question($this->banglaTopic, "Question {$i}?"))
        ->all();

    // Slow and correct is fine. Thinking is allowed.
    $session = completedMock($questions, [
        0 => [0, 360_000],
        1 => [0, 5_000], 2 => [0, 5_000], 3 => [0, 5_000], 4 => [0, 5_000],
    ], durationMinutes: 10);

    expect($this->analyser->analyse($session)['pacing']['time_sinks'])->toBeEmpty();
});

it('flags a rushed mistake separately from a time sink', function () {
    $questions = collect(range(1, 5))
        ->map(fn ($i) => question($this->banglaTopic, "Question {$i}?"))
        ->all();

    // Answered in 2s against a 120s budget, and wrong: carelessness, not
    // ignorance. A different problem needing a different fix.
    $session = completedMock($questions, [
        0 => [1, 2_000],
        1 => [0, 60_000], 2 => [0, 60_000], 3 => [0, 60_000], 4 => [0, 60_000],
    ], durationMinutes: 10);

    $pacing = $this->analyser->analyse($session)['pacing'];

    expect($pacing['rushed_mistakes'])->toHaveCount(1)
        ->and($pacing['rushed_mistakes'][0]['time_ms'])->toBe(2_000)
        ->and($pacing['time_sinks'])->toBeEmpty();
});

it('returns empty pacing rather than inventing numbers without timings', function () {
    $questions = [question($this->banglaTopic, 'One?')];

    $session = completedMock($questions, [0 => [0, null]]);

    $pacing = $this->analyser->analyse($session)['pacing'];

    expect($pacing['median_ms'])->toBeNull()
        ->and($pacing['time_sinks'])->toBeEmpty()
        ->and($pacing['rushed_mistakes'])->toBeEmpty();
});

it('reports the readiness delta against the snapshot taken before the exam', function () {
    $questions = [question($this->banglaTopic, 'One?')];

    $session = completedMock($questions, [0 => [0, 5000]]);

    $readiness = $this->analyser->analyse($session)['readiness'];

    // `before` comes from the snapshot stored at creation, not a recomputation
    // of the same data — otherwise the delta would always be zero.
    expect($readiness['before'])->toBe(10.0)
        ->and($readiness['estimated_score_before'])->toBe(2)
        ->and($readiness['delta'])->toBe(round($readiness['after'] - 10.0, 2));
});
