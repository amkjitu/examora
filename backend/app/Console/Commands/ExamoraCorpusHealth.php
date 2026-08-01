<?php

namespace App\Console\Commands;

use App\Models\Examora\Exam;
use App\Services\Corpus\CorpusHealthCheck;
use Illuminate\Console\Command;

class ExamoraCorpusHealth extends Command
{
    protected $signature = 'examora:corpus-health
                            {exam? : Exam slug (defaults to config examora.default_exam)}
                            {--strict : Exit non-zero on warnings as well as problems}';

    protected $description = 'Report whether the question bank is fit to build a product on.';

    public function handle(CorpusHealthCheck $check): int
    {
        $slug = $this->argument('exam') ?? config('examora.default_exam');
        $exam = Exam::where('slug', $slug)->first();

        if (! $exam) {
            $this->components->error("Exam [{$slug}] not found.");

            return self::FAILURE;
        }

        $result = $check->run($exam);
        $stats = $result['stats'];

        $this->components->info("Corpus health — {$exam->name}");
        $this->newLine();

        foreach ([
            'Questions' => $stats['questions'] ?? 0,
            'Distinct facts' => $stats['distinct_facts'] ?? '—',
            'Sessions covered' => ($stats['sessions'] ?? 0).($stats['year_range'] ? "  ({$stats['year_range']})" : ''),
            'Classified' => isset($stats['classified'])
                ? $stats['classified'].' of '.$stats['questions'].'  ('.round(($stats['classified_ratio'] ?? 0) * 100).'%)'
                : '—',
            'Topics with questions' => ($stats['topics_with_questions'] ?? 0).' of '.($stats['topics_total'] ?? 0),
        ] as $label => $value) {
            $this->components->twoColumnDetail("<fg=gray>{$label}</>", (string) $value);
        }

        $this->section('Fatal — the corpus cannot support the product', $result['fatal'], 'red');
        $this->section('Problems — real damage, fixable', $result['problems'], 'yellow');
        $this->section('Worth knowing', $result['warnings'], 'gray');

        $this->newLine();

        if ($result['ok'] && $result['warnings'] === []) {
            $this->components->info('Healthy. Scores and forecasts built on this can be trusted as far as the data goes.');

            return self::SUCCESS;
        }

        if ($result['ok']) {
            $this->components->warn('Usable, with caveats above.');

            return $this->option('strict') ? self::FAILURE : self::SUCCESS;
        }

        $this->components->error(
            'Not fit to score. Numbers derived from this corpus will look confident and be wrong.'
        );

        return self::FAILURE;
    }

    private function section(string $title, array $lines, string $colour): void
    {
        if ($lines === []) {
            return;
        }

        $this->newLine();
        $this->line("<fg={$colour};options=bold>{$title}</>");

        foreach ($lines as $line) {
            $this->line("  <fg={$colour}>·</> {$line}");
        }
    }
}
