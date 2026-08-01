<?php

use App\Models\Examora\PastQuestion;
use App\Services\Corpus\CorpusHealthCheck;
use App\Services\Corpus\QuestionImporter;
use Tests\Examora;

/** Writes a CSV to a temp path and returns it. */
function csv(string $body): string
{
    $path = sys_get_temp_dir().'/examora-import-'.uniqid().'.csv';
    file_put_contents($path, trim($body)."\n");

    return $path;
}

beforeEach(function () {
    $this->exam = Examora::exam();
    $this->subject = Examora::subject($this->exam, 'Bangladesh Affairs', 30);
    $this->topic = Examora::topic($this->subject, 'Liberation War of 1971');
    $this->importer = app(QuestionImporter::class);
});

it('imports a clean file and fingerprints every row', function () {
    $file = csv(<<<'CSV'
    year,topic,question,option_a,option_b,option_c,option_d,answer
    2023,Liberation War of 1971,Who led the Mukti Bahini?,Osmani,Zia,Khaled,Shafiullah,A
    2021,Liberation War of 1971,How many sectors were there?,11,9,7,13,11
    CSV);

    $report = $this->importer->import($this->exam, $file, dryRun: false);

    expect($report['rows'])->toBe(2)
        ->and($report['valid'])->toBe(2)
        ->and($report['imported'])->toBe(2)
        ->and($report['errors'])->toBeEmpty();

    $question = PastQuestion::where('year', 2023)->first();

    expect($question->topic_id)->toBe($this->topic->id)
        ->and($question->options)->toBe(['Osmani', 'Zia', 'Khaled', 'Shafiullah'])
        ->and($question->correct_index)->toBe(0)
        ->and($question->fingerprint)->not->toBeNull();
});

it('writes nothing on a dry run, but predicts exactly what a commit would do', function () {
    $file = csv(<<<'CSV'
    year,topic,question,option_a,option_b,option_c,option_d,answer
    2023,Liberation War of 1971,Who led the Mukti Bahini?,Osmani,Zia,Khaled,Shafiullah,A
    CSV);

    $dry = $this->importer->import($this->exam, $file, dryRun: true);

    expect($dry['valid'])->toBe(1)
        ->and($dry['imported'])->toBe(0)
        ->and(PastQuestion::count())->toBe(0);

    $wet = $this->importer->import($this->exam, $file, dryRun: false);

    expect($wet['imported'])->toBe($dry['valid']);
});

it('reads an answer key given as a letter, an index, or the answer text', function () {
    $file = csv(<<<'CSV'
    year,topic,question,option_a,option_b,option_c,option_d,answer
    2023,Liberation War of 1971,Letter key?,alpha,bravo,charlie,delta,C
    2022,Liberation War of 1971,Numeric key?,alpha,bravo,charlie,delta,2
    2021,Liberation War of 1971,Text key?,alpha,bravo,charlie,delta,delta
    CSV);

    $this->importer->import($this->exam, $file, dryRun: false);

    expect(PastQuestion::where('year', 2023)->value('correct_index'))->toBe(2)  // C
        ->and(PastQuestion::where('year', 2022)->value('correct_index'))->toBe(1)  // 1-based 2
        ->and(PastQuestion::where('year', 2021)->value('correct_index'))->toBe(3); // by text
});

it('reads a numeric answer as the option text, not as a position', function () {
    // Numeric options are everywhere in this material — years, counts. Reading
    // "11" as a position here would pick the wrong option or reject the row.
    $file = csv(<<<'CSV'
    year,topic,question,option_a,option_b,option_c,option_d,answer
    2023,Liberation War of 1971,How many sectors were there?,11,9,7,13,11
    2022,Liberation War of 1971,In which year was independence declared?,1971,1972,1970,1969,1971
    CSV);

    $this->importer->import($this->exam, $file, dryRun: false);

    expect(PastQuestion::where('year', 2023)->value('correct_index'))->toBe(0)
        ->and(PastQuestion::where('year', 2022)->value('correct_index'))->toBe(0);
});

it('still reads a bare number as a position when no option matches it', function () {
    $file = csv(<<<'CSV'
    year,topic,question,option_a,option_b,option_c,option_d,answer
    2023,Liberation War of 1971,Which is third?,alpha,bravo,charlie,delta,3
    CSV);

    $this->importer->import($this->exam, $file, dryRun: false);

    expect(PastQuestion::first()->correct_index)->toBe(2);
});

it('refuses a row rather than guessing its answer key', function () {
    $file = csv(<<<'CSV'
    year,topic,question,option_a,option_b,option_c,option_d,answer
    2023,Liberation War of 1971,No key at all?,alpha,bravo,charlie,delta,
    2023,Liberation War of 1971,Key out of range?,alpha,bravo,charlie,delta,Z
    CSV);

    $report = $this->importer->import($this->exam, $file, dryRun: false);

    // A wrong key silently corrupts importance, lessons and the forecast at
    // once. Rejecting the row is always the cheaper failure.
    expect($report['imported'])->toBe(0)
        ->and($report['errors'])->toHaveCount(2)
        ->and($report['errors'][0])->toContain('Line 2')
        ->and($report['errors'][1])->toContain('answer key');
});

it('reports every rejection with the line number a human can find', function () {
    $file = csv(<<<'CSV'
    year,topic,question,option_a,option_b,option_c,option_d,answer
    2023,Liberation War of 1971,Fine question?,alpha,bravo,charlie,delta,A
    1823,Liberation War of 1971,Implausible year?,alpha,bravo,charlie,delta,A
    2023,Liberation War of 1971,,alpha,bravo,charlie,delta,A
    2023,Liberation War of 1971,Only one option?,alpha,,,,A
    CSV);

    $report = $this->importer->import($this->exam, $file, dryRun: true);

    expect($report['errors'])->toHaveCount(3);

    // Line numbers account for the header, so they match what an editor shows.
    expect($report['errors'][0])->toContain('Line 3')->toContain('1823')
        ->and($report['errors'][1])->toContain('Line 4')
        ->and($report['errors'][2])->toContain('Line 5');
});

it('imports an unmatched topic unclassified instead of dropping the question', function () {
    $file = csv(<<<'CSV'
    year,topic,question,option_a,option_b,option_c,option_d,answer
    2023,No Such Chapter,A good question on an unknown topic?,alpha,bravo,charlie,delta,A
    CSV);

    $report = $this->importer->import($this->exam, $file, dryRun: false);

    // Losing a verified question over a spelling difference would be worse than
    // importing it for a reviewer to classify.
    expect($report['imported'])->toBe(1)
        ->and($report['unmatched_topics'])->toContain('No Such Chapter')
        ->and(PastQuestion::first()->topic_id)->toBeNull();
});

it('matches a topic by slug and by loose name', function () {
    $file = csv(<<<'CSV'
    year,topic,question,option_a,option_b,option_c,option_d,answer
    2023,liberation-war-of-1971,By slug?,alpha,bravo,charlie,delta,A
    2022,LIBERATION WAR OF 1971,By loose name?,alpha,bravo,charlie,delta,A
    CSV);

    $this->importer->import($this->exam, $file, dryRun: false);

    expect(PastQuestion::whereNotNull('topic_id')->count())->toBe(2);
});

it('skips a question already in the bank for that year', function () {
    $file = csv(<<<'CSV'
    year,topic,question,option_a,option_b,option_c,option_d,answer
    2023,Liberation War of 1971,Who led the Mukti Bahini?,Osmani,Zia,Khaled,Shafiullah,A
    CSV);

    $this->importer->import($this->exam, $file, dryRun: false);
    $second = $this->importer->import($this->exam, $file, dryRun: false);

    expect($second['imported'])->toBe(0)
        ->and($second['skipped_duplicate'])->toBe(1)
        ->and(PastQuestion::count())->toBe(1);
});

it('still records the same question from a different year', function () {
    $file = csv(<<<'CSV'
    year,topic,question,option_a,option_b,option_c,option_d,answer
    2023,Liberation War of 1971,Who led the Mukti Bahini?,Osmani,Zia,Khaled,Shafiullah,A
    2019,Liberation War of 1971,Who led the Mukti Bahini?,Osmani,Zia,Khaled,Shafiullah,A
    CSV);

    $this->importer->import($this->exam, $file, dryRun: false);

    // One fact, examined twice — the signal the importance scorer runs on.
    expect(PastQuestion::count())->toBe(2)
        ->and(PastQuestion::distinct('fingerprint')->count('fingerprint'))->toBe(1);
});

it('flags a duplicate inside the file itself', function () {
    $file = csv(<<<'CSV'
    year,topic,question,option_a,option_b,option_c,option_d,answer
    2023,Liberation War of 1971,Repeated row?,alpha,bravo,charlie,delta,A
    2023,Liberation War of 1971,Repeated row?,alpha,bravo,charlie,delta,A
    CSV);

    $report = $this->importer->import($this->exam, $file, dryRun: false);

    expect($report['imported'])->toBe(1)
        ->and($report['warnings'][0])->toContain('duplicate of line 2');
});

it('imports JSON as readily as CSV', function () {
    $path = sys_get_temp_dir().'/examora-import-'.uniqid().'.json';
    file_put_contents($path, json_encode(['questions' => [[
        'year' => 2023,
        'topic' => 'Liberation War of 1971',
        'question' => 'From JSON?',
        'options' => ['alpha', 'bravo', 'charlie', 'delta'],
        'answer' => 'B',
    ]]]));

    $report = $this->importer->import($this->exam, $path, dryRun: false);

    expect($report['imported'])->toBe(1)
        ->and(PastQuestion::first()->correct_index)->toBe(1)
        ->and(PastQuestion::first()->options)->toHaveCount(4);
});

// ---------------------------------------------------------------------------
// Health check
// ---------------------------------------------------------------------------

it('calls an empty corpus fatal', function () {
    $result = app(CorpusHealthCheck::class)->run($this->exam);

    expect($result['ok'])->toBeFalse()
        ->and($result['fatal'][0])->toContain('empty');
});

it('refuses to call a corpus healthy on too few sessions', function () {
    Examora::fact($this->topic, 'Only question?', ['a', 'b', 'c', 'd'], 0, [2023, 2022]);

    $result = app(CorpusHealthCheck::class)->run($this->exam->fresh());

    expect($result['ok'])->toBeFalse()
        ->and(implode(' ', $result['fatal']))->toContain('sessions of history');
});

it('treats a large share of unclassified questions as fatal', function () {
    for ($year = 2018; $year <= 2024; $year++) {
        Examora::fact($this->topic, "Classified {$year}?", ['a', 'b', 'c', 'd'], 0, [$year]);
    }

    // Half the bank with no topic: every ranking is understated, silently.
    for ($year = 2018; $year <= 2024; $year++) {
        PastQuestion::create([
            'exam_id' => $this->exam->id, 'topic_id' => null, 'year' => $year,
            'stem' => "Unclassified {$year}?", 'options' => ['a', 'b', 'c', 'd'],
            'correct_index' => 0, 'difficulty' => 'medium', 'marks' => 1,
        ]);
    }

    $result = app(CorpusHealthCheck::class)->run($this->exam->fresh());

    expect($result['ok'])->toBeFalse()
        ->and(implode(' ', $result['fatal']))->toContain('no topic');
});

it('spots a gap in the middle of the covered range', function () {
    // 2021 missing: an archive gap the forecast would read as "nothing asked".
    foreach ([2018, 2019, 2020, 2022, 2023, 2024] as $year) {
        Examora::fact($this->topic, "Question {$year}?", ['a', 'b', 'c', 'd'], 0, [$year]);
    }

    $result = app(CorpusHealthCheck::class)->run($this->exam->fresh());

    expect(implode(' ', $result['problems']))->toContain('2021');
});

it('spots an answer key pointing outside its options', function () {
    foreach (range(2019, 2024) as $year) {
        Examora::fact($this->topic, "Question {$year}?", ['a', 'b', 'c', 'd'], 0, [$year]);
    }

    PastQuestion::first()->update(['correct_index' => 9]);

    $result = app(CorpusHealthCheck::class)->run($this->exam->fresh());

    expect($result['ok'])->toBeFalse()
        ->and(implode(' ', $result['problems']))->toContain('outside their options');
});

it('reports a healthy corpus as healthy', function () {
    // Ten sessions, all classified, no gaps, keys in range.
    foreach (range(2016, 2025) as $year) {
        Examora::fact($this->topic, "Question A {$year}?", ['a', 'b', 'c', 'd'], 0, [$year]);
        Examora::fact($this->topic, "Question B {$year}?", ['a', 'b', 'c', 'd'], 1, [$year]);
        Examora::fact($this->topic, "Question C {$year}?", ['a', 'b', 'c', 'd'], 2, [$year]);
    }

    $result = app(CorpusHealthCheck::class)->run($this->exam->fresh());

    expect($result['fatal'])->toBeEmpty()
        ->and($result['problems'])->toBeEmpty()
        ->and($result['ok'])->toBeTrue()
        ->and($result['stats']['classified_ratio'])->toBe(1.0);
});
