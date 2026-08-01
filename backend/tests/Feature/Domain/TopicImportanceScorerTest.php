<?php

use App\Services\Learning\TopicImportanceScorer;
use Tests\Examora;

it('ranks a frequently and recently examined topic above a rare one', function () {
    $exam = Examora::exam();
    $subject = Examora::subject($exam, 'Bangladesh Affairs', 30);

    $hot = Examora::topic($subject, 'Liberation War');
    Examora::fact($hot, 'Commander-in-Chief?', ['Osmani', 'b', 'c', 'd'], 0, [2020, 2022, 2024]);
    Examora::fact($hot, 'How many sectors?', ['11', 'b', 'c', 'd'], 0, [2021, 2023]);

    $cold = Examora::topic($subject, 'Obscure Chapter');
    Examora::fact($cold, 'Something rare?', ['x', 'y', 'z', 'w'], 0, [2013]);

    app(TopicImportanceScorer::class)->recomputeExam($exam);

    $hotStats = $hot->fresh('statistics')->statistics;
    $coldStats = $cold->fresh('statistics')->statistics;

    expect($hotStats->importance_score)->toBeGreaterThan($coldStats->importance_score)
        ->and($hotStats->probability_stars)->toBeGreaterThan($coldStats->probability_stars)
        ->and($hotStats->question_count)->toBe(5)
        ->and($hotStats->last_year)->toBe(2024);
});

it('weights recent sessions more heavily than old ones', function () {
    $exam = Examora::exam();
    $subject = Examora::subject($exam);

    $recent = Examora::topic($subject, 'Recent Topic');
    Examora::fact($recent, 'Recent?', ['a', 'b', 'c', 'd'], 0, [2023, 2024, 2025]);

    $old = Examora::topic($subject, 'Old Topic');
    Examora::fact($old, 'Old?', ['a', 'b', 'c', 'd'], 0, [2010, 2011, 2012]);

    app(TopicImportanceScorer::class)->recomputeExam($exam);

    expect($recent->fresh('statistics')->statistics->recency_score)
        ->toBeGreaterThan($old->fresh('statistics')->statistics->recency_score);
});

it('gives a heavier subject a boost at equal question frequency', function () {
    $exam = Examora::exam();

    $heavy = Examora::topic(Examora::subject($exam, 'Bangla', 35), 'Heavy Chapter');
    $light = Examora::topic(Examora::subject($exam, 'Ethics', 10), 'Light Chapter');

    foreach ([$heavy, $light] as $topic) {
        Examora::fact($topic, "Question for {$topic->name}?", ['a', 'b', 'c', 'd'], 0, [2021, 2023]);
    }

    app(TopicImportanceScorer::class)->recomputeExam($exam);

    expect($heavy->fresh('statistics')->statistics->importance_score)
        ->toBeGreaterThan($light->fresh('statistics')->statistics->importance_score);
});

it('counts a repeated question as one fact examined many times', function () {
    $exam = Examora::exam();
    $topic = Examora::populatedTopic($exam);

    app(TopicImportanceScorer::class)->recomputeExam($exam);

    $stats = $topic->fresh('statistics')->statistics;

    // 6 rows, but only 3 distinct facts — estimated study time follows facts.
    expect($stats->question_count)->toBe(6)
        ->and($stats->estimated_minutes)->toBeLessThanOrEqual(15)
        ->and($stats->years)->toHaveKey('2024');
});
