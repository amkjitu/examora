<?php

use App\Models\Examora\Question;
use App\Models\Examora\QuizSession;
use App\Services\Knowledge\Indexer;
use App\Services\Learning\QuizEngine;
use App\Services\Learning\TopicImportanceScorer;
use Tests\Examora;
use Tymon\JWTAuth\Facades\JWTAuth;

beforeEach(function () {
    $this->exam = Examora::exam([
        'name' => 'BCS Preliminary',
        'slug' => 'bcs-preliminary',
        'total_marks' => 20,
        'duration_minutes' => 20,
        'negative_marking' => 0.50,
    ]);

    $this->topic = Examora::populatedTopic($this->exam);

    // A second subject so the breakdown has more than one row to split.
    $science = Examora::subject($this->exam, 'General Science', 15);
    $physiology = Examora::topic($science, 'Human Physiology');
    Examora::fact($physiology, 'How many chambers?', ['4', '2', '3', '6'], 0, [2019, 2022]);
    Examora::fact($physiology, 'Which vitamin from sunlight?', ['D', 'A', 'C', 'K'], 0, [2021]);

    app(Indexer::class)->indexPastQuestions($this->exam->id);
    app(TopicImportanceScorer::class)->recomputeExam($this->exam);

    // Promote every past question into the practice bank the mock draws from.
    foreach ($this->exam->pastQuestions()->get() as $past) {
        Question::updateOrCreate(['past_question_id' => $past->id], [
            'exam_id' => $past->exam_id,
            'topic_id' => $past->topic_id,
            'origin' => Question::ORIGIN_PAST,
            'stem' => $past->stem,
            'options' => $past->options,
            'correct_index' => $past->correct_index,
            'explanation' => $past->explanation,
            'difficulty' => $past->difficulty,
            'is_active' => true,
            'meta' => ['years' => [$past->year]],
        ]);
    }

    $this->user = Examora::user();
    $this->headers = ['Authorization' => 'Bearer '.JWTAuth::fromUser($this->user)];
    $this->engine = app(QuizEngine::class);
});

it('never returns the answer key during a strict exam', function () {
    $session = $this->engine->startMock($this->user, $this->exam->id, 6, QuizSession::MODE_STRICT);
    $question = Question::find($session->question_ids[0]);

    $response = $this->postJson("/api/v1/quizzes/{$session->id}/answer", [
        'question_id' => $question->id,
        'selected_index' => $question->correct_index,
        'time_ms' => 5000,
    ], $this->headers)->assertOk();

    $data = $response->json('data');

    expect($data)->toHaveKey('recorded')
        ->and($data['mode'])->toBe('strict')
        ->and($data)->not->toHaveKey('is_correct')
        ->and($data)->not->toHaveKey('correct_index')
        ->and($data)->not->toHaveKey('explanation');

    // The answer is still recorded — silence is not the same as discarding it.
    expect($session->answers()->count())->toBe(1);
});

it('does give feedback in practice mode', function () {
    $session = $this->engine->startMock($this->user, $this->exam->id, 6, QuizSession::MODE_PRACTICE);
    $question = Question::find($session->question_ids[0]);

    $this->postJson("/api/v1/quizzes/{$session->id}/answer", [
        'question_id' => $question->id,
        'selected_index' => $question->correct_index,
    ], $this->headers)
        ->assertOk()
        ->assertJsonPath('data.mode', 'practice')
        ->assertJsonPath('data.is_correct', true)
        ->assertJsonPath('data.correct_index', (int) $question->correct_index);
});

it('computes remaining time from the wall clock, so closing the tab gains nothing', function () {
    $session = $this->engine->startMock($this->user, $this->exam->id, 6, QuizSession::MODE_STRICT, durationMinutes: 30);

    expect($session->secondsRemaining())->toBeGreaterThan(29 * 60)
        ->and($session->secondsRemaining())->toBeLessThanOrEqual(30 * 60);

    // Student closes the tab for ten minutes and comes back.
    $this->travel(10)->minutes();

    $resumed = $this->getJson("/api/v1/quizzes/{$session->id}", $this->headers)->assertOk();

    expect($resumed->json('data.seconds_remaining'))->toBeLessThanOrEqual(20 * 60)
        ->and($resumed->json('data.seconds_remaining'))->toBeGreaterThan(19 * 60)
        ->and($resumed->json('data.mode'))->toBe('strict');
});

it('rejects answers once the clock has run out', function () {
    $session = $this->engine->startMock($this->user, $this->exam->id, 6, QuizSession::MODE_STRICT, durationMinutes: 5);
    $question = Question::find($session->question_ids[0]);

    $this->travel(6)->minutes();

    $this->postJson("/api/v1/quizzes/{$session->id}/answer", [
        'question_id' => $question->id,
        'selected_index' => 0,
    ], $this->headers)
        ->assertStatus(422)
        ->assertJsonPath('message', 'Time is up for this exam. Submit it to see your result.');
});

it('auto-submits an expired paper when the student returns to it', function () {
    $session = $this->engine->startMock($this->user, $this->exam->id, 6, QuizSession::MODE_STRICT, durationMinutes: 5);

    $this->travel(6)->minutes();

    $this->getJson("/api/v1/quizzes/{$session->id}", $this->headers)
        ->assertOk()
        ->assertJsonPath('data.seconds_remaining', 0);

    expect($session->fresh()->isComplete())->toBeTrue();
});

it('applies negative marking, and does not hide a negative score', function () {
    $session = $this->engine->startMock($this->user, $this->exam->id, 6, QuizSession::MODE_STRICT);
    $questions = Question::whereIn('id', $session->question_ids)->get();

    // Answer every question wrong: 0 correct, 6 wrong at -0.5 = -3 marks.
    foreach ($questions as $question) {
        $wrong = ((int) $question->correct_index + 1) % count($question->options);

        $this->postJson("/api/v1/quizzes/{$session->id}/answer", [
            'question_id' => $question->id,
            'selected_index' => $wrong,
        ], $this->headers)->assertOk();
    }

    $result = $this->postJson("/api/v1/quizzes/{$session->id}/complete", [], $this->headers)->assertOk();

    expect($result->json('data.correct'))->toBe(0)
        ->and($result->json('data.wrong'))->toBe($questions->count())
        ->and((float) $result->json('data.marks'))->toBe(-0.5 * $questions->count())
        ->and((float) $result->json('data.score'))->toBeLessThan(0);
});

it('does not penalise skipped questions', function () {
    $session = $this->engine->startMock($this->user, $this->exam->id, 6, QuizSession::MODE_STRICT);
    $question = Question::find($session->question_ids[0]);

    // Answer one correctly, skip the rest.
    $this->postJson("/api/v1/quizzes/{$session->id}/answer", [
        'question_id' => $question->id,
        'selected_index' => $question->correct_index,
    ], $this->headers)->assertOk();

    $result = $this->postJson("/api/v1/quizzes/{$session->id}/complete", [], $this->headers)->assertOk();

    expect($result->json('data.correct'))->toBe(1)
        ->and($result->json('data.wrong'))->toBe(0)
        ->and($result->json('data.skipped'))->toBe(5)
        ->and((float) $result->json('data.marks'))->toBe(1.0);
});

it('refuses to show analysis before the paper is submitted', function () {
    $session = $this->engine->startMock($this->user, $this->exam->id, 6, QuizSession::MODE_STRICT);

    $this->getJson("/api/v1/quizzes/{$session->id}/analysis", $this->headers)
        ->assertStatus(422)
        ->assertJsonPath('code', 'session_incomplete');
});

it('breaks the result down by subject, weakest first', function () {
    $session = $this->engine->startMock($this->user, $this->exam->id, 9, QuizSession::MODE_STRICT);
    $questions = Question::with('topic.subject')->whereIn('id', $session->question_ids)->get();

    foreach ($questions as $question) {
        // Get Bangladesh Affairs right, General Science wrong — a deliberate
        // lopsided result the breakdown must surface.
        $subject = $question->topic?->subject?->name;
        $choice = $subject === 'General Science'
            ? ((int) $question->correct_index + 1) % count($question->options)
            : (int) $question->correct_index;

        $this->postJson("/api/v1/quizzes/{$session->id}/answer", [
            'question_id' => $question->id,
            'selected_index' => $choice,
            'time_ms' => 6000,
        ], $this->headers)->assertOk();
    }

    $this->postJson("/api/v1/quizzes/{$session->id}/complete", [], $this->headers)->assertOk();

    $analysis = $this->getJson("/api/v1/quizzes/{$session->id}/analysis", $this->headers)->assertOk();

    $subjects = collect($analysis->json('data.subjects'));

    // Every question is accounted for exactly once.
    expect($subjects->sum('total'))->toBe($questions->count());

    // Weakest subject sorts first.
    expect($subjects->first()['score_percent'])
        ->toBeLessThanOrEqual($subjects->last()['score_percent']);

    $science = $subjects->firstWhere('subject', 'General Science');

    if ($science) {
        // JSON collapses 0.0 to 0, so compare numerically rather than by type.
        expect($science['correct'])->toBe(0)
            ->and((float) $science['accuracy'])->toBe(0.0);
    }
});

it('returns a full review with explanations and a readiness delta', function () {
    $session = $this->engine->startMock($this->user, $this->exam->id, 6, QuizSession::MODE_STRICT);

    foreach (Question::whereIn('id', $session->question_ids)->get() as $question) {
        $this->postJson("/api/v1/quizzes/{$session->id}/answer", [
            'question_id' => $question->id,
            'selected_index' => $question->correct_index,
            'time_ms' => 7000,
        ], $this->headers)->assertOk();
    }

    $this->postJson("/api/v1/quizzes/{$session->id}/complete", [], $this->headers)->assertOk();

    $data = $this->getJson("/api/v1/quizzes/{$session->id}/analysis", $this->headers)
        ->assertOk()
        ->json('data');

    // Review: numbered in paper order, with the key now visible.
    expect($data['review'])->toHaveCount(6)
        ->and($data['review'][0]['position'])->toBe(1)
        ->and($data['review'][0])->toHaveKey('correct_index')
        ->and($data['review'][0]['explanation'])->not->toBeNull()
        ->and($data['review'][0]['is_correct'])->toBeTrue();

    // Pacing: timings were recorded, so the medians are real.
    expect($data['pacing']['median_ms'])->toBe(7000)
        ->and($data['pacing']['budget_ms'])->toBeGreaterThan(0);

    // Readiness moved forward after a clean sweep.
    expect($data['readiness']['after'])->toBeGreaterThan($data['readiness']['before'])
        ->and($data['readiness']['delta'])->toBeGreaterThan(0);

    expect((float) $data['summary']['accuracy_on_attempted'])->toBe(1.0)
        ->and($data['summary']['mode'])->toBe('strict');
});

it('flags slow wrong answers as time sinks', function () {
    $session = $this->engine->startMock($this->user, $this->exam->id, 6, QuizSession::MODE_STRICT, durationMinutes: 6);
    $questions = Question::whereIn('id', $session->question_ids)->get();

    // One minute budget per question. Burn four minutes on the first and get it
    // wrong; answer the rest quickly and correctly.
    foreach ($questions as $index => $question) {
        $slow = $index === 0;

        $this->postJson("/api/v1/quizzes/{$session->id}/answer", [
            'question_id' => $question->id,
            'selected_index' => $slow
                ? ((int) $question->correct_index + 1) % count($question->options)
                : (int) $question->correct_index,
            'time_ms' => $slow ? 240_000 : 5_000,
        ], $this->headers)->assertOk();
    }

    $this->postJson("/api/v1/quizzes/{$session->id}/complete", [], $this->headers)->assertOk();

    $pacing = $this->getJson("/api/v1/quizzes/{$session->id}/analysis", $this->headers)
        ->assertOk()
        ->json('data.pacing');

    expect($pacing['time_sinks'])->toHaveCount(1)
        ->and($pacing['time_sinks'][0]['question_id'])->toBe($questions->first()->id)
        ->and($pacing['time_sinks'][0]['time_ms'])->toBe(240_000);
});

it('keeps someone else out of your exam analysis', function () {
    $session = $this->engine->startMock($this->user, $this->exam->id, 6, QuizSession::MODE_STRICT);
    $this->engine->complete($session);

    $intruder = Examora::user();

    $this->getJson("/api/v1/quizzes/{$session->id}/analysis", [
        'Authorization' => 'Bearer '.JWTAuth::fromUser($intruder),
    ])->assertForbidden();
});
