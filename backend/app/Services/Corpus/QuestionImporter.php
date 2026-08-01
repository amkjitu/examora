<?php

namespace App\Services\Corpus;

use App\Models\Examora\Exam;
use App\Models\Examora\PastQuestion;
use App\Models\Examora\Topic;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Bulk import of a past-question corpus from CSV or JSON.
 *
 * The single biggest thing standing between this platform and being useful is a
 * real, verified question bank. The PDF pipeline handles scanned papers; this
 * handles the far more common case — someone has the questions in a spreadsheet
 * already, typed up by hand or bought from a publisher.
 *
 * Two principles:
 *
 *   1. **Validate everything, import nothing silently.** A row with a
 *      mis-numbered answer key does more damage than a row that fails to
 *      import, because it corrupts importance scores, lessons and the forecast
 *      at once and nobody notices. Every problem is reported with its line
 *      number.
 *
 *   2. **Dry run by default.** You should be able to see exactly what a file
 *      would do before it does it.
 */
class QuestionImporter
{
    /** Column names accepted for each field, first match wins. */
    private const ALIASES = [
        'stem' => ['stem', 'question', 'question_text', 'text'],
        'options' => ['options', 'choices'],
        'option_a' => ['option_a', 'a', 'opt_a', 'choice_a'],
        'option_b' => ['option_b', 'b', 'opt_b', 'choice_b'],
        'option_c' => ['option_c', 'c', 'opt_c', 'choice_c'],
        'option_d' => ['option_d', 'd', 'opt_d', 'choice_d'],
        'option_e' => ['option_e', 'e', 'opt_e', 'choice_e'],
        'answer' => ['answer', 'correct', 'correct_answer', 'key', 'correct_option'],
        'correct_index' => ['correct_index', 'answer_index'],
        'topic' => ['topic', 'chapter', 'topic_name', 'topic_slug'],
        'subject' => ['subject', 'subject_name'],
        'year' => ['year', 'exam_year'],
        'session' => ['session', 'session_label', 'exam_session'],
        'explanation' => ['explanation', 'why', 'note'],
        'difficulty' => ['difficulty', 'level'],
    ];

    public function __construct(private readonly CorpusHealthCheck $health) {}

    /**
     * @return array{
     *   rows:int, valid:int, imported:int, skipped_duplicate:int,
     *   errors:array<int,string>, warnings:array<int,string>, unmatched_topics:array<int,string>
     * }
     */
    public function import(Exam $exam, string $path, bool $dryRun = true): array
    {
        $rows = $this->read($path);

        if ($rows->isEmpty()) {
            throw new RuntimeException("No rows found in [{$path}].");
        }

        $topics = Topic::query()
            ->whereHas('subject', fn ($q) => $q->where('exam_id', $exam->id))
            ->with('subject:id,name')
            ->get();

        $report = [
            'rows' => $rows->count(),
            'valid' => 0,
            'imported' => 0,
            'skipped_duplicate' => 0,
            'errors' => [],
            'warnings' => [],
            'unmatched_topics' => [],
        ];

        $prepared = [];
        $seenInFile = [];

        foreach ($rows as $index => $row) {
            // +2: one for the header, one because humans count from 1.
            $line = $index + 2;
            $parsed = $this->parseRow($row, $topics, $exam, $line, $report);

            if ($parsed === null) {
                continue;
            }

            // A file that repeats a question within itself is usually a copy
            // paste error worth reporting, not silently collapsing.
            $key = $parsed['fingerprint'].':'.$parsed['year'];

            if (isset($seenInFile[$key])) {
                $report['warnings'][] = "Line {$line}: duplicate of line {$seenInFile[$key]} within this file — skipped.";

                continue;
            }

            $seenInFile[$key] = $line;
            $report['valid']++;
            $prepared[] = $parsed;
        }

        if ($dryRun || $prepared === []) {
            $report['skipped_duplicate'] = $this->countExisting($exam, $prepared);

            return $report;
        }

        DB::transaction(function () use ($exam, $prepared, &$report) {
            foreach ($prepared as $row) {
                $existing = PastQuestion::where('exam_id', $exam->id)
                    ->where('fingerprint', $row['fingerprint'])
                    ->where('year', $row['year'])
                    ->exists();

                if ($existing) {
                    $report['skipped_duplicate']++;

                    continue;
                }

                PastQuestion::create([
                    'exam_id' => $exam->id,
                    'topic_id' => $row['topic_id'],
                    'year' => $row['year'],
                    'session_label' => $row['session'],
                    'stem' => $row['stem'],
                    'options' => $row['options'],
                    'correct_index' => $row['correct_index'],
                    'explanation' => $row['explanation'],
                    'difficulty' => $row['difficulty'],
                    'marks' => 1,
                    'source' => 'import:'.basename($row['_path'] ?? 'file'),
                    'fingerprint' => $row['fingerprint'],
                ]);

                $report['imported']++;
            }
        });

        return $report;
    }

    /**
     * Turns one row into a validated question, or records why it cannot be one.
     */
    private function parseRow(array $row, Collection $topics, Exam $exam, int $line, array &$report): ?array
    {
        $stem = trim((string) $this->value($row, 'stem'));

        if ($stem === '') {
            $report['errors'][] = "Line {$line}: no question text.";

            return null;
        }

        $options = $this->options($row);

        if (count($options) < 2) {
            $report['errors'][] = "Line {$line}: needs at least two options, found ".count($options).'.';

            return null;
        }

        $correctIndex = $this->correctIndex($row, $options);

        if ($correctIndex === null) {
            // Refused rather than guessed. A wrong key is worse than no row.
            $report['errors'][] = "Line {$line}: no answer key, or it does not match any option.";

            return null;
        }

        $year = (int) $this->value($row, 'year');

        if ($year < 1950 || $year > (int) date('Y') + 1) {
            $report['errors'][] = "Line {$line}: year '{$year}' is not plausible.";

            return null;
        }

        $topicId = null;
        $topicName = trim((string) $this->value($row, 'topic'));

        if ($topicName !== '') {
            $topic = $this->matchTopic($topicName, $topics);

            if ($topic) {
                $topicId = $topic->id;
            } else {
                // Imported unclassified rather than rejected: a reviewer can
                // assign the topic, and losing a verified question over a
                // spelling difference would be worse.
                $report['unmatched_topics'][] = $topicName;
                $report['warnings'][] = "Line {$line}: topic '{$topicName}' not found — imported unclassified.";
            }
        } else {
            $report['warnings'][] = "Line {$line}: no topic given — imported unclassified.";
        }

        $difficulty = strtolower(trim((string) $this->value($row, 'difficulty')));

        return [
            'stem' => $stem,
            'options' => $options,
            'correct_index' => $correctIndex,
            'year' => $year,
            'session' => trim((string) $this->value($row, 'session')) ?: null,
            'explanation' => trim((string) $this->value($row, 'explanation')) ?: null,
            'difficulty' => in_array($difficulty, ['easy', 'medium', 'hard'], true) ? $difficulty : 'medium',
            'topic_id' => $topicId,
            'fingerprint' => PastQuestion::fingerprintFor($stem, $options),
        ];
    }

    /** @return array<int, string> */
    private function options(array $row): array
    {
        $combined = $this->value($row, 'options');

        if (filled($combined)) {
            // JSON array, or a delimited string.
            if (is_array($combined)) {
                return array_values(array_filter(array_map('trim', $combined), 'filled'));
            }

            $decoded = json_decode((string) $combined, true);

            if (is_array($decoded)) {
                return array_values(array_filter(array_map('trim', $decoded), 'filled'));
            }

            $parts = preg_split('/\s*(?:\||;|\r?\n)\s*/', (string) $combined) ?: [];

            if (count($parts) >= 2) {
                return array_values(array_filter(array_map('trim', $parts), 'filled'));
            }
        }

        $options = [];

        foreach (['option_a', 'option_b', 'option_c', 'option_d', 'option_e'] as $key) {
            $value = trim((string) $this->value($row, $key));

            if ($value !== '') {
                $options[] = $value;
            }
        }

        return $options;
    }

    /**
     * Accepts a letter (A/B/C/D), a 0- or 1-based index, or the answer text
     * itself. Returns null when it cannot be resolved unambiguously.
     */
    private function correctIndex(array $row, array $options): ?int
    {
        $explicit = $this->value($row, 'correct_index');

        if ($explicit !== null && $explicit !== '') {
            $index = (int) $explicit;

            return isset($options[$index]) ? $index : null;
        }

        $answer = trim((string) $this->value($row, 'answer'));

        if ($answer === '') {
            return null;
        }

        // Text match first, and this order matters.
        //
        // Numeric options are everywhere in this material — years, counts,
        // "how many sectors". For a question with options [11, 9, 7, 13] and the
        // answer "11", reading the answer as a position would silently pick the
        // wrong option or reject a perfectly good row. An exact match against
        // the option text is unambiguous evidence and must win.
        $normalise = fn (string $v) => preg_replace('/\s+/', ' ', mb_strtolower(trim($v)));
        $target = $normalise($answer);

        foreach ($options as $index => $option) {
            if ($normalise($option) === $target) {
                return $index;
            }
        }

        // A single letter: A, B, C…
        if (preg_match('/^[A-Za-z]$/', $answer)) {
            $index = ord(strtoupper($answer)) - 65;

            return isset($options[$index]) ? $index : null;
        }

        // A bare number, with no option matching it as text: a position.
        // 1..n is 1-based, 0 is 0-based.
        if (preg_match('/^\d+$/', $answer)) {
            $number = (int) $answer;

            if ($number === 0 && isset($options[0])) {
                return 0;
            }

            return isset($options[$number - 1]) ? $number - 1 : null;
        }

        return null;
    }

    /** Matches a topic by slug, then exact name, then a slugified comparison. */
    private function matchTopic(string $name, Collection $topics): ?Topic
    {
        $slug = Str::slug($name);

        return $topics->firstWhere('slug', $slug)
            ?? $topics->first(fn (Topic $t) => mb_strtolower($t->name) === mb_strtolower($name))
            ?? $topics->first(fn (Topic $t) => Str::slug($t->name) === $slug);
    }

    /**
     * How many prepared rows already exist, matched on (fingerprint, year) —
     * the same pair the import itself uses.
     *
     * Counting by fingerprint alone would over-report, because a question asked
     * in four sittings is four rows, and a dry run must predict exactly what a
     * commit would do.
     */
    private function countExisting(Exam $exam, array $prepared): int
    {
        if ($prepared === []) {
            return 0;
        }

        $existing = PastQuestion::where('exam_id', $exam->id)
            ->whereIn('fingerprint', array_column($prepared, 'fingerprint'))
            ->get(['fingerprint', 'year'])
            ->map(fn ($q) => $q->fingerprint.':'.$q->year)
            ->flip();

        return collect($prepared)
            ->filter(fn (array $row) => $existing->has($row['fingerprint'].':'.$row['year']))
            ->count();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function read(string $path): Collection
    {
        if (! is_file($path)) {
            throw new RuntimeException("File not found: {$path}");
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'json') {
            $decoded = json_decode((string) file_get_contents($path), true);

            if (! is_array($decoded)) {
                throw new RuntimeException("Could not parse JSON in {$path}.");
            }

            // Accept either a bare array or {"questions": [...]}.
            return collect($decoded['questions'] ?? $decoded);
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Could not open {$path}.");
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            throw new RuntimeException("{$path} appears to be empty.");
        }

        // Strip a UTF-8 BOM, which Excel adds and which silently breaks the
        // first column name.
        $header[0] = preg_replace('/^\x{FEFF}/u', '', (string) $header[0]);
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

        $rows = [];

        while (($line = fgetcsv($handle)) !== false) {
            if ($line === [null] || $line === []) {
                continue;
            }

            $rows[] = array_combine(
                $header,
                array_pad(array_slice($line, 0, count($header)), count($header), null)
            );
        }

        fclose($handle);

        return collect($rows);
    }

    /** Reads a field by any of its accepted column names. */
    private function value(array $row, string $field): mixed
    {
        foreach (self::ALIASES[$field] ?? [$field] as $alias) {
            if (array_key_exists($alias, $row) && $row[$alias] !== null && $row[$alias] !== '') {
                return $row[$alias];
            }
        }

        return null;
    }
}
