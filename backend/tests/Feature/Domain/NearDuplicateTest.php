<?php

use App\Models\Examora\PastQuestion;
use App\Models\Examora\Topic;
use App\Services\Knowledge\Indexer;
use App\Services\Prediction\NearDuplicateDetector;
use App\Services\Prediction\RepeatPredictor;
use Tests\Examora;

/** Adds one past question and returns it. */
function pastQuestion(
    Topic $topic,
    string $stem,
    array $options,
    int $correct,
    int $year,
): PastQuestion {
    return PastQuestion::create([
        'exam_id' => $topic->subject->exam_id,
        'topic_id' => $topic->id,
        'year' => $year,
        'session_label' => "Session {$year}",
        'stem' => $stem,
        'options' => $options,
        'correct_index' => $correct,
        'difficulty' => 'medium',
        'marks' => 1,
        'fingerprint' => PastQuestion::fingerprintFor($stem, $options),
    ]);
}

beforeEach(function () {
    $this->exam = Examora::exam();
    $this->subject = Examora::subject($this->exam);
    $this->topic = Examora::topic($this->subject, 'Liberation War');
    $this->detector = app(NearDuplicateDetector::class);
});

it('spots the same question reworded across sittings', function () {
    $options = ['Colonel M. A. G. Osmani', 'Major Ziaur Rahman', 'Khaled Mosharraf', 'K. M. Shafiullah'];

    // Same question, three words different — a new fingerprint, but the same fact.
    pastQuestion($this->topic, 'Who was the Commander-in-Chief of the Mukti Bahini?', $options, 0, 2018);
    pastQuestion($this->topic, 'Who was the Commander in Chief of the Mukti Bahini forces?', $options, 0, 2023);

    app(Indexer::class)->indexPastQuestions($this->exam->id);

    $clusters = $this->detector->clusters($this->exam);

    expect($clusters)->toHaveCount(1)
        ->and($clusters[0]['distinct_fingerprints'])->toBe(2)
        ->and($clusters[0]['years'])->toBe([2018, 2023])
        ->and($clusters[0]['answers_agree'])->toBeTrue();
});

it('leaves genuinely different questions in the same topic apart', function () {
    pastQuestion($this->topic, 'Who was the Commander-in-Chief of the Mukti Bahini?', ['Osmani', 'Zia', 'Khaled', 'Shafiullah'], 0, 2018);
    pastQuestion($this->topic, 'How many sectors was Bangladesh divided into during the war?', ['11', '9', '7', '13'], 0, 2023);

    app(Indexer::class)->indexPastQuestions($this->exam->id);

    // Questions on one topic share names and vocabulary, so the threshold has
    // to be strict enough that topical similarity alone does not merge them.
    expect($this->detector->clusters($this->exam))->toBeEmpty();
});

it('never clusters across topics', function () {
    $other = Examora::topic($this->subject, 'Language Movement');
    $stem = 'In which year did the movement reach its climax?';
    $options = ['1952', '1948', '1954', '1956'];

    pastQuestion($this->topic, $stem, $options, 0, 2018);
    pastQuestion($other, $stem, $options, 0, 2023);

    app(Indexer::class)->indexPastQuestions($this->exam->id);

    // Identical text, different topics: almost certainly a classification issue
    // rather than a repeat, so it is not treated as one.
    expect($this->detector->clusters($this->exam))->toBeEmpty();
});

it('does not restate what the exact fingerprint already catches', function () {
    $options = ['Osmani', 'Zia', 'Khaled', 'Shafiullah'];

    // Verbatim repeat: one fingerprint, already handled by RepeatPredictor.
    pastQuestion($this->topic, 'Who led the Mukti Bahini?', $options, 0, 2018);
    pastQuestion($this->topic, 'Who led the Mukti Bahini?', $options, 0, 2023);

    app(Indexer::class)->indexPastQuestions($this->exam->id);

    expect($this->detector->clusters($this->exam))->toBeEmpty();
});

it('refuses to treat a cluster with conflicting answers as a repeat', function () {
    $stem = 'Who was the Commander-in-Chief of the Mukti Bahini?';
    $variant = 'Who was the Commander in Chief of the Mukti Bahini forces?';
    $options = ['Colonel M. A. G. Osmani', 'Major Ziaur Rahman', 'Khaled Mosharraf', 'K. M. Shafiullah'];

    pastQuestion($this->topic, $stem, $options, 0, 2018);
    // Same wording family, different key — either a data error or two different
    // questions. Either way it must not silently become one fact.
    pastQuestion($this->topic, $variant, $options, 1, 2023);

    app(Indexer::class)->indexPastQuestions($this->exam->id);

    $clusters = $this->detector->clusters($this->exam);

    expect($clusters)->toHaveCount(1)
        ->and($clusters[0]['answers_agree'])->toBeFalse()
        ->and($clusters[0]['conflicting_answers'])->toHaveCount(2);

    // Surfaced for a human, but kept out of the forecast.
    expect($this->detector->repeatEvidence($this->exam))->toBeEmpty();
});

it('never writes to past_questions', function () {
    $options = ['Colonel M. A. G. Osmani', 'Major Ziaur Rahman', 'Khaled Mosharraf', 'K. M. Shafiullah'];

    pastQuestion($this->topic, 'Who was the Commander-in-Chief of the Mukti Bahini?', $options, 0, 2018);
    pastQuestion($this->topic, 'Who was the Commander in Chief of the Mukti Bahini forces?', $options, 0, 2023);

    app(Indexer::class)->indexPastQuestions($this->exam->id);

    $before = PastQuestion::get(['id', 'fingerprint', 'stem'])->toArray();
    $this->detector->clusters($this->exam);

    expect(PastQuestion::get(['id', 'fingerprint', 'stem'])->toArray())->toBe($before);
});

it('counts a reworded repeat as a return in the forecast', function () {
    $options = ['Colonel M. A. G. Osmani', 'Major Ziaur Rahman', 'Khaled Mosharraf', 'K. M. Shafiullah'];

    pastQuestion($this->topic, 'Who was the Commander-in-Chief of the Mukti Bahini?', $options, 0, 2018);
    pastQuestion($this->topic, 'Who was the Commander in Chief of the Mukti Bahini forces?', $options, 0, 2023);

    app(Indexer::class)->indexPastQuestions($this->exam->id);

    $candidates = app(RepeatPredictor::class)->candidates($this->exam);

    // Before near-duplicate detection this produced nothing at all: two distinct
    // fingerprints, each with a single appearance.
    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]['appearances'])->toBe(2)
        ->and($candidates[0]['years'])->toBe([2018, 2023])
        ->and($candidates[0]['reworded'])->toBeTrue()
        ->and($candidates[0]['evidence'][0])->toContain('reworded between sittings');
});

it('still reports a verbatim repeat as verbatim', function () {
    $options = ['Osmani', 'Zia', 'Khaled', 'Shafiullah'];

    pastQuestion($this->topic, 'Who led the Mukti Bahini?', $options, 0, 2018);
    pastQuestion($this->topic, 'Who led the Mukti Bahini?', $options, 0, 2023);

    app(Indexer::class)->indexPastQuestions($this->exam->id);

    $candidates = app(RepeatPredictor::class)->candidates($this->exam);

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]['reworded'])->toBeFalse()
        ->and($candidates[0]['evidence'][0])->toContain('verbatim');
});
