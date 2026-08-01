<?php

use App\Models\Examora\Lesson;
use App\Models\Examora\MemoryItem;
use App\Models\Examora\Question;
use App\Services\Knowledge\Indexer;
use App\Services\Learning\LessonGenerator;
use App\Services\Learning\QuizEngine;
use App\Services\Learning\TopicImportanceScorer;
use Tests\Examora;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * The vertical slice, end to end, exactly as a student walks it:
 * enrol -> see chapters ranked by exam probability -> open a lesson ->
 * take the check -> get scheduled for revision -> see it on the dashboard.
 */
beforeEach(function () {
    $this->exam = Examora::exam(['name' => 'BCS Preliminary', 'slug' => 'bcs-preliminary']);
    $this->topic = Examora::populatedTopic($this->exam);

    // A second, weaker topic so ranking has something to rank.
    $minor = Examora::topic($this->topic->subject, 'Minor Chapter');
    Examora::fact($minor, 'Rarely asked?', ['a', 'b', 'c', 'd'], 0, [2011]);

    app(Indexer::class)->indexPastQuestions($this->exam->id);
    app(TopicImportanceScorer::class)->recomputeExam($this->exam);

    $this->user = Examora::user();

    // Mint the token without logging in: auth()->login() would leave the guard
    // authenticated for the rest of the test, which would silently hide any
    // missing-auth regression.
    $this->token = JWTAuth::fromUser($this->user);
});

function authed(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

it('lists chapters ordered by exam probability, without requiring auth', function () {
    $response = $this->getJson("/api/v1/exams/{$this->exam->slug}/topics")->assertOk();

    $data = $response->json('data');

    expect($data)->toHaveCount(2)
        ->and($data[0]['name'])->toBe('Liberation War')
        ->and($data[0]['probability_stars'])->toBeGreaterThan($data[1]['probability_stars'])
        ->and($data[0]['question_count'])->toBe(6)
        ->and($data[0]['estimated_minutes'])->toBeGreaterThan(0);
});

it('walks a student from enrolment to a scheduled revision', function () {
    // 1. Enrol with a target session and date.
    $this->postJson("/api/v1/exams/{$this->exam->slug}/enroll", [
        'target_session' => 'BCS 48',
        'target_date' => now()->addDays(91)->toDateString(),
        'daily_minutes_goal' => 20,
    ], authed())
        ->assertCreated()
        ->assertJsonPath('data.days_left', 91);

    // 2. Open the lesson. It is generated on demand and grounded in past questions.
    $lessonResponse = $this->getJson("/api/v1/topics/{$this->topic->id}/lesson", authed())->assertOk();

    $lessonResponse
        ->assertJsonPath('data.topic.name', 'Liberation War')
        ->assertJsonPath('data.exam_intelligence.questions_asked_previously', 6);

    $lessonId = $lessonResponse->json('data.id');
    expect($lessonResponse->json('data.key_points'))->not->toBeEmpty()
        ->and($lessonResponse->json('data.cards'))->not->toBeEmpty();

    // The lesson only teaches facts that actually appeared.
    $keyPointText = implode(' ', $lessonResponse->json('data.key_points'));
    expect($keyPointText)->toContain('Osmani');

    // 3. Complete it — XP and streak move.
    $this->postJson("/api/v1/lessons/{$lessonId}/complete", [], authed())
        ->assertOk()
        ->assertJsonPath('data.streak_days', 1)
        ->assertJsonPath('data.level', 1);

    // 4. Take the post-lesson check.
    $quiz = $this->postJson('/api/v1/quizzes', [
        'type' => 'lesson_check',
        'lesson_id' => $lessonId,
    ], authed())->assertCreated();

    $sessionId = $quiz->json('data.id');
    $questions = $quiz->json('data.questions');

    expect($questions)->not->toBeEmpty();

    // The answer key never leaves the server on an unanswered question.
    expect($questions[0])->not->toHaveKey('correct_index')
        ->and($questions[0])->not->toHaveKey('explanation');

    // 5. Answer correctly.
    $question = Question::find($questions[0]['id']);

    $this->postJson("/api/v1/quizzes/{$sessionId}/answer", [
        'question_id' => $question->id,
        'selected_index' => $question->correct_index,
        'time_ms' => 4200,
        'confidence' => 4,
    ], authed())
        ->assertOk()
        ->assertJsonPath('data.is_correct', true)
        ->assertJsonPath('data.correct_index', (int) $question->correct_index);

    // 6. That answer created a scheduled memory item.
    $item = MemoryItem::where('user_id', $this->user->id)
        ->where('question_id', $question->id)
        ->first();

    expect($item)->not->toBeNull()
        ->and($item->due_at)->not->toBeNull();

    // 7. Finish the quiz.
    $this->postJson("/api/v1/quizzes/{$sessionId}/complete", [], authed())
        ->assertOk()
        ->assertJsonPath('data.correct', 1);

    // 8. The dashboard reflects all of it.
    $dashboard = $this->getJson('/api/v1/dashboard?refresh=1', authed())->assertOk();

    $dashboard
        ->assertJsonPath('data.exam.name', 'BCS Preliminary')
        ->assertJsonPath('data.target.days_left', 91)
        ->assertJsonPath('data.gamification.streak_days', 1);

    expect($dashboard->json('data.today.items'))->not->toBeEmpty()
        ->and($dashboard->json('data.gamification.xp'))->toBeGreaterThan(0)
        ->and($dashboard->json('data.readiness.readiness'))->toBeGreaterThan(0);
});

it('records a wrong answer and returns the explanation', function () {
    $lesson = $this->getJson("/api/v1/topics/{$this->topic->id}/lesson", authed())->json('data');

    $quiz = $this->postJson('/api/v1/quizzes', [
        'type' => 'lesson_check',
        'lesson_id' => $lesson['id'],
    ], authed())->json('data');

    $question = Question::find($quiz['questions'][0]['id']);
    $wrongIndex = ((int) $question->correct_index + 1) % count($question->options);

    $this->postJson("/api/v1/quizzes/{$quiz['id']}/answer", [
        'question_id' => $question->id,
        'selected_index' => $wrongIndex,
        'time_ms' => 12000,
    ], authed())
        ->assertOk()
        ->assertJsonPath('data.is_correct', false)
        ->assertJsonPath('data.explanation', $question->explanation);
});

it('refuses to let a student answer inside someone else quiz', function () {
    // Set the victim's quiz up in-process. The JWT guard and token parser are
    // both singletons that latch onto the first bearer token seen in a request
    // cycle, so the intruder's call must be the only HTTP request in this test.
    $lesson = app(LessonGenerator::class)->generate($this->topic);
    $quiz = app(QuizEngine::class)->startLessonCheck($this->user, $lesson);

    $intruder = Examora::user();

    $this->postJson("/api/v1/quizzes/{$quiz->id}/answer", [
        'question_id' => $quiz->question_ids[0],
        'selected_index' => 0,
    ], ['Authorization' => 'Bearer '.JWTAuth::fromUser($intruder)])->assertForbidden();
});

it('requires authentication for the dashboard', function () {
    $this->getJson('/api/v1/dashboard')->assertUnauthorized();
});

it('reuses a generated lesson instead of paying for it twice', function () {
    $first = $this->getJson("/api/v1/topics/{$this->topic->id}/lesson", authed())->json('data.id');
    $second = $this->getJson("/api/v1/topics/{$this->topic->id}/lesson", authed())->json('data.id');

    expect($first)->toBe($second)
        ->and(Lesson::where('topic_id', $this->topic->id)->count())->toBe(1);
});
