<?php

namespace App\Services\Corpus;

use App\Models\Examora\Exam;
use App\Models\Examora\PastQuestion;
use App\Models\Examora\Topic;
use Illuminate\Support\Facades\DB;

/**
 * Tells you whether a question bank is fit to build a product on.
 *
 * Every number Examora shows a student — star ratings, expected question counts,
 * readiness, the forecast — is a function of `past_questions`. A corpus with
 * missing answer keys or a three-year gap produces confident-looking output that
 * is quietly wrong, and nobody finds out until a student sits the exam.
 *
 * So this reports problems in the order they do damage, and refuses to call a
 * corpus healthy when it is not.
 */
class CorpusHealthCheck
{
    /** Below this many sessions, importance and forecast are not meaningful. */
    public const MIN_SESSIONS = 5;

    /** Below this many questions, a topic's statistics are noise. */
    public const MIN_QUESTIONS_PER_TOPIC = 3;

    /** Share of questions that must carry a topic for scoring to be trustworthy. */
    public const MIN_CLASSIFIED_RATIO = 0.9;

    public function run(Exam $exam): array
    {
        $questions = PastQuestion::where('exam_id', $exam->id)->get();
        $total = $questions->count();

        if ($total === 0) {
            return [
                'ok' => false,
                'fatal' => ['The question bank is empty. Nothing downstream means anything yet.'],
                'problems' => [],
                'warnings' => [],
                'stats' => ['questions' => 0],
            ];
        }

        $years = $questions->pluck('year')->unique()->sort()->values();
        $classified = $questions->whereNotNull('topic_id')->count();

        $stats = [
            'questions' => $total,
            'sessions' => $years->count(),
            'year_range' => $years->isEmpty() ? null : $years->first().'–'.$years->last(),
            'classified' => $classified,
            'classified_ratio' => round($classified / $total, 3),
            'distinct_facts' => $questions->pluck('fingerprint')->filter()->unique()->count(),
            'topics_with_questions' => $questions->pluck('topic_id')->filter()->unique()->count(),
            'topics_total' => Topic::whereHas('subject', fn ($q) => $q->where('exam_id', $exam->id))->count(),
        ];

        $fatal = [];
        $problems = [];
        $warnings = [];

        // --- Fatal: the corpus cannot support the product at all --------------

        if ($stats['sessions'] < self::MIN_SESSIONS) {
            $fatal[] = "Only {$stats['sessions']} sessions of history. Importance scoring and the "
                .'forecast need at least '.self::MIN_SESSIONS.' to say anything useful.';
        }

        if ($stats['classified_ratio'] < self::MIN_CLASSIFIED_RATIO) {
            $unclassified = $total - $classified;
            $fatal[] = "{$unclassified} of {$total} questions have no topic ("
                .round((1 - $stats['classified_ratio']) * 100)
                .'%). Topic scores are computed only from classified questions, so every '
                .'ranking is currently understated.';
        }

        // --- Problems: real damage, fixable ----------------------------------

        $noKey = $questions->whereNull('correct_index')->count();

        if ($noKey > 0) {
            $problems[] = "{$noKey} questions have no answer key. They cannot be practised and "
                .'will never appear in a quiz.';
        }

        $badKey = $questions->filter(
            fn (PastQuestion $q) => $q->correct_index !== null
                && ! isset(($q->options ?? [])[$q->correct_index])
        );

        if ($badKey->isNotEmpty()) {
            $ids = $badKey->take(5)->pluck('id')->implode(', ');
            $problems[] = "{$badKey->count()} questions have an answer key pointing outside their "
                ."options (ids: {$ids}). These will mark correct answers wrong.";
        }

        $noFingerprint = $questions->whereNull('fingerprint')->count();

        if ($noFingerprint > 0) {
            $problems[] = "{$noFingerprint} questions have no fingerprint, so repeats across "
                .'sessions cannot be detected for them. Re-run `examora:bootstrap`.';
        }

        // A year missing from the middle of the range is almost always a gap in
        // the archive, not a year the exam did not run — and the forecast reads
        // it as "nothing was asked", which inflates overdue-ness everywhere.
        $missing = collect(range($years->first(), $years->last()))
            ->reject(fn ($y) => $years->contains($y))
            ->values();

        if ($missing->isNotEmpty()) {
            $problems[] = "No questions for {$missing->implode(', ')} inside the covered range. "
                .'The forecast will treat those as sessions where nothing was asked.';
        }

        $currentYear = (int) date('Y');
        $behind = $currentYear - (int) $years->last();

        if ($behind > 1) {
            $problems[] = "The most recent session in the bank is {$years->last()}, {$behind} years "
                .'ago. The forecast is for a sitting that has probably already happened.';
        }

        // --- Warnings: worth knowing ------------------------------------------

        // havingRaw, not having('n', ...): PostgreSQL will not accept a select
        // alias in HAVING. SQLite does, so the test suite would never catch it.
        $thin = DB::table('past_questions')
            ->where('exam_id', $exam->id)
            ->whereNotNull('topic_id')
            ->select('topic_id')
            ->groupBy('topic_id')
            ->havingRaw('count(*) < ?', [self::MIN_QUESTIONS_PER_TOPIC])
            ->get()
            ->count();

        if ($thin > 0) {
            $warnings[] = "{$thin} topics have fewer than ".self::MIN_QUESTIONS_PER_TOPIC
                .' questions. Their star ratings are guesses and will show as low confidence.';
        }

        $empty = $stats['topics_total'] - $stats['topics_with_questions'];

        if ($empty > 0) {
            $warnings[] = "{$empty} topics in the syllabus have no questions at all. They cannot be "
                .'scored, ranked or turned into lessons.';
        }

        // Does the corpus reflect the exam's own mark distribution? A subject
        // worth 35 marks with 2% of the questions will be systematically
        // under-ranked no matter how good the scorer is.
        foreach ($this->subjectCoverage($exam, $total) as $row) {
            if ($row['expected_share'] > 0.05 && $row['actual_share'] < $row['expected_share'] * 0.4) {
                $warnings[] = sprintf(
                    '%s carries %d%% of the exam marks but only %d%% of the questions — under-represented.',
                    $row['subject'],
                    round($row['expected_share'] * 100),
                    round($row['actual_share'] * 100),
                );
            }
        }

        return [
            'ok' => $fatal === [] && $problems === [],
            'fatal' => $fatal,
            'problems' => $problems,
            'warnings' => $warnings,
            'stats' => $stats,
        ];
    }

    /** @return array<int, array{subject:string, expected_share:float, actual_share:float}> */
    private function subjectCoverage(Exam $exam, int $total): array
    {
        $totalMarks = max(1, (int) $exam->subjects()->sum('marks'));

        return DB::table('subjects')
            ->leftJoin('topics', 'topics.subject_id', '=', 'subjects.id')
            ->leftJoin('past_questions', 'past_questions.topic_id', '=', 'topics.id')
            ->where('subjects.exam_id', $exam->id)
            ->select('subjects.name', 'subjects.marks', DB::raw('count(past_questions.id) as n'))
            ->groupBy('subjects.id', 'subjects.name', 'subjects.marks')
            ->get()
            ->map(fn ($row) => [
                'subject' => $row->name,
                'expected_share' => $row->marks / $totalMarks,
                'actual_share' => $total > 0 ? $row->n / $total : 0.0,
            ])
            ->all();
    }
}
