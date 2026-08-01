<?php

use App\Models\Examora\TopicPrediction;
use App\Services\Prediction\PredictionEngine;
use App\Services\Prediction\RepeatPredictor;
use Tests\Examora;

beforeEach(function () {
    $this->engine = app(PredictionEngine::class);
});

it('raises the forecast for a topic overdue against its own cycle', function () {
    $sessions = range(2014, 2024);

    // Both topics appear at the same rate. One is bang on cycle, one is late.
    $onCycle = $this->engine->predictTopic([2018 => 1, 2020 => 1, 2022 => 1, 2024 => 1], $sessions, 2025);
    $overdue = $this->engine->predictTopic([2014 => 1, 2016 => 1, 2018 => 1, 2020 => 1], $sessions, 2025);

    expect($overdue['overdue_ratio'])->toBeGreaterThan($onCycle['overdue_ratio'])
        ->and($overdue['signals']['mean_gap_years'])->toBe($onCycle['signals']['mean_gap_years']);

    // Overdue-ness only nudges — a topic absent for years is not suddenly a
    // certainty. The adjustment is damped on purpose.
    expect($overdue['appearance_probability'])->toBeLessThan(0.95);
});

it('refuses to call one sighting a pattern', function () {
    $sessions = range(2014, 2024);

    $once = $this->engine->predictTopic([2019 => 1], $sessions, 2025);
    $regular = $this->engine->predictTopic(
        [2015 => 1, 2017 => 1, 2019 => 1, 2021 => 1, 2023 => 1],
        $sessions,
        2025,
    );

    expect($once['confidence'])->toBeLessThan(0.45)
        ->and($regular['confidence'])->toBeGreaterThan($once['confidence'])
        ->and($once['evidence'])->toContain('Thin evidence — treat this as a hint, not a plan.');
});

it('widens the expected range when confidence is low', function () {
    $sessions = range(2014, 2024);

    $thin = $this->engine->predictTopic([2019 => 2], $sessions, 2025);
    $solid = $this->engine->predictTopic(
        [2015 => 1, 2017 => 1, 2019 => 1, 2021 => 1, 2023 => 1],
        $sessions,
        2025,
    );

    $thinSpread = $thin['expected_high'] - $thin['expected_low'];
    $solidSpread = $solid['expected_high'] - $solid['expected_low'];

    expect($thin['confidence'])->toBeLessThan($solid['confidence'])
        // Comparable expectation, but the thin one must look vaguer.
        ->and($thinSpread / max(0.01, $thin['expected_questions']))
        ->toBeGreaterThan($solidSpread / max(0.01, $solid['expected_questions']));
});

it('prefers an irregular topic less than a metronomic one at equal frequency', function () {
    $sessions = range(2010, 2024);

    $regular = $this->engine->predictTopic([2016 => 1, 2018 => 1, 2020 => 1, 2022 => 1], $sessions, 2025);
    $erratic = $this->engine->predictTopic([2010 => 1, 2011 => 1, 2022 => 1, 2024 => 1], $sessions, 2025);

    expect($regular['confidence'])->toBeGreaterThan($erratic['confidence']);
});

it('detects a rising and a falling topic', function () {
    $sessions = range(2014, 2024);

    $rising = $this->engine->predictTopic([2022 => 2, 2023 => 2, 2024 => 2], $sessions, 2025);
    $falling = $this->engine->predictTopic([2014 => 3, 2015 => 3, 2016 => 3, 2017 => 2], $sessions, 2025);

    expect($rising['trend'])->toBe(TopicPrediction::TREND_RISING)
        ->and($falling['trend'])->toBe(TopicPrediction::TREND_FALLING);
});

it('produces a probability consistent with its Poisson expectation', function () {
    $prediction = $this->engine->predictTopic(
        [2020 => 2, 2021 => 2, 2022 => 2, 2023 => 2, 2024 => 2],
        range(2020, 2024),
        2025,
    );

    // P(at least one) = 1 - e^(-lambda)
    expect($prediction['appearance_probability'])
        ->toBe(round(1 - exp(-$prediction['expected_questions']), 4));
});

it('forecasts the session after the archive, not the current year', function () {
    $exam = Examora::exam();
    $subject = Examora::subject($exam);
    $topic = Examora::topic($subject, 'Stale Topic');

    // An archive that stops well before today.
    Examora::fact($topic, 'Old question?', ['a', 'b', 'c', 'd'], 0, [2018, 2019, 2020]);

    $this->engine->forecast($exam->fresh());

    // Skipping to the current year would count every unarchived session as one
    // where nothing was asked, making everything look falsely overdue.
    expect(TopicPrediction::where('exam_id', $exam->id)->value('target_year'))->toBe(2021);

    $freshness = $this->engine->freshness($exam);

    expect($freshness['last_session_in_bank'])->toBe(2020)
        ->and($freshness['is_current'])->toBeFalse()
        ->and($freshness['sessions_behind'])->toBeGreaterThan(0);
});

it('replaces a superseded forecast rather than stacking a second one beside it', function () {
    $exam = Examora::exam();
    $topic = Examora::populatedTopic($exam);

    $this->engine->forecast($exam->fresh(), 2025);
    $this->engine->forecast($exam->fresh(), 2026);

    $rows = TopicPrediction::where('topic_id', $topic->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->target_year)->toBe(2026);
});

it('scores a backtest against the held-out session', function () {
    $exam = Examora::exam();
    $subject = Examora::subject($exam);

    $regular = Examora::topic($subject, 'Regular Topic');
    Examora::fact($regular, 'Regular?', ['a', 'b', 'c', 'd'], 0, [2019, 2020, 2021, 2022, 2023, 2024]);

    $rare = Examora::topic($subject, 'Rare Topic');
    Examora::fact($rare, 'Rare?', ['a', 'b', 'c', 'd'], 0, [2019]);

    $result = $this->engine->backtest($exam->fresh());

    expect($result['ok'])->toBeTrue()
        ->and($result['held_out_session'])->toBe(2024)
        ->and($result['topics_scored'])->toBe(2)
        // A model that beats "always guess 50%" on this data is doing something.
        ->and($result['brier_score'])->toBeLessThan($result['baseline_brier']);
});

it('declines to backtest without enough history', function () {
    $exam = Examora::exam();
    $topic = Examora::topic(Examora::subject($exam), 'Thin Topic');
    Examora::fact($topic, 'Only once?', ['a', 'b', 'c', 'd'], 0, [2023]);

    expect($this->engine->backtest($exam->fresh())['ok'])->toBeFalse();
});

it('only offers repeat candidates that have actually repeated', function () {
    $exam = Examora::exam();
    $subject = Examora::subject($exam);
    $topic = Examora::topic($subject, 'Liberation War');

    Examora::fact($topic, 'Repeated question?', ['a', 'b', 'c', 'd'], 0, [2015, 2019, 2023]);
    Examora::fact($topic, 'One-off question?', ['w', 'x', 'y', 'z'], 0, [2021]);

    $candidates = app(RepeatPredictor::class)->candidates($exam->fresh());

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]['stem'])->toBe('Repeated question?')
        ->and($candidates[0]['appearances'])->toBe(3)
        ->and($candidates[0]['years'])->toBe([2015, 2019, 2023])
        ->and($candidates[0]['answer'])->toBe('a');
});

it('ranks a due repeat above one that has just been asked', function () {
    $exam = Examora::exam();
    $topic = Examora::topic(Examora::subject($exam), 'Mixed');

    // Same repeat count; one is at the end of its cycle, one just appeared.
    Examora::fact($topic, 'Due for a return?', ['a', 'b', 'c', 'd'], 0, [2014, 2018]);
    Examora::fact($topic, 'Just asked?', ['a', 'b', 'c', 'd'], 0, [2020, 2024]);

    $candidates = app(RepeatPredictor::class)->candidates($exam->fresh());

    expect($candidates[0]['stem'])->toBe('Due for a return?')
        ->and($candidates[0]['overdue_ratio'])->toBeGreaterThan($candidates[1]['overdue_ratio']);
});

it('serves the forecast over the API with its caveats attached', function () {
    $exam = Examora::exam(['slug' => 'bcs-preliminary']);
    Examora::populatedTopic($exam);

    $this->engine->forecast($exam->fresh());

    $response = $this->getJson("/api/v1/exams/{$exam->slug}/predictions")->assertOk();

    // The disclaimer and freshness travel with every response, so no client can
    // render a forecast stripped of its context.
    expect($response->json('data.disclaimer'))->toContain('not a syllabus')
        ->and($response->json('data.freshness'))->toHaveKey('is_current')
        ->and($response->json('data.target_year'))->toBe(2025);

    $top = $response->json('data.topics.0');

    expect($top['evidence'])->not->toBeEmpty()
        ->and($top)->toHaveKey('confidence_label')
        ->and($top['expected_range'])->toHaveCount(2);
});

it('says so plainly when no forecast has been computed', function () {
    $exam = Examora::exam(['slug' => 'unforecast']);

    $this->getJson("/api/v1/exams/{$exam->slug}/predictions")
        ->assertOk()
        ->assertJsonPath('data', null);
});
