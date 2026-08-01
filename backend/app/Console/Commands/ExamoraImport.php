<?php

namespace App\Console\Commands;

use App\Models\Examora\Exam;
use App\Services\Corpus\QuestionImporter;
use Illuminate\Console\Command;
use Throwable;

class ExamoraImport extends Command
{
    protected $signature = 'examora:import
                            {file : CSV or JSON file of past questions}
                            {--exam= : Exam slug (defaults to config examora.default_exam)}
                            {--commit : Actually import. Without this it is a dry run}
                            {--bootstrap : Re-index, re-score and re-forecast after importing}';

    protected $description = 'Import a past-question corpus from CSV or JSON. Dry run unless --commit.';

    public function handle(QuestionImporter $importer): int
    {
        $slug = $this->option('exam') ?? config('examora.default_exam');
        $exam = Exam::where('slug', $slug)->first();

        if (! $exam) {
            $this->components->error("Exam [{$slug}] not found.");

            return self::FAILURE;
        }

        $file = $this->argument('file');
        $commit = (bool) $this->option('commit');

        $this->components->info(($commit ? 'Importing into ' : 'Dry run against ').$exam->name);

        try {
            $report = $importer->import($exam, $file, dryRun: ! $commit);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Rows read</>', (string) $report['rows']);
        $this->components->twoColumnDetail('<fg=gray>Valid</>', (string) $report['valid']);
        $this->components->twoColumnDetail('<fg=gray>Rejected</>', (string) count($report['errors']));

        if ($commit) {
            $this->components->twoColumnDetail('<fg=green>Imported</>', (string) $report['imported']);
            $this->components->twoColumnDetail('<fg=gray>Already present</>', (string) $report['skipped_duplicate']);
        } else {
            $this->components->twoColumnDetail('<fg=gray>Already in the bank</>', (string) $report['skipped_duplicate']);
        }

        $this->report('Rejected rows', $report['errors'], 'red');
        $this->report('Warnings', $report['warnings'], 'yellow');

        if ($report['unmatched_topics'] !== []) {
            $unique = array_unique($report['unmatched_topics']);
            $this->newLine();
            $this->components->warn(count($unique).' topic names did not match the syllabus:');

            foreach (array_slice($unique, 0, 15) as $name) {
                $this->line("  <fg=yellow>·</> {$name}");
            }

            $this->line('  <fg=gray>Add these to the taxonomy, or fix the spelling in the file. '
                .'Questions were imported unclassified rather than dropped.</>');
        }

        $this->newLine();

        if (! $commit) {
            $this->components->info('Dry run — nothing was written. Re-run with --commit to import.');

            return self::SUCCESS;
        }

        if ($this->option('bootstrap') && $report['imported'] > 0) {
            $this->newLine();
            $this->call('examora:bootstrap', ['exam' => $exam->slug, '--skip-lessons' => true]);
        } elseif ($report['imported'] > 0) {
            $this->components->info('Run `php artisan examora:bootstrap` to fold these into the scores.');
        }

        return self::SUCCESS;
    }

    private function report(string $title, array $lines, string $colour): void
    {
        if ($lines === []) {
            return;
        }

        $this->newLine();
        $this->line("<fg={$colour};options=bold>{$title} (".count($lines).')</>');

        foreach (array_slice($lines, 0, 20) as $line) {
            $this->line("  <fg={$colour}>·</> {$line}");
        }

        if (count($lines) > 20) {
            $this->line('  <fg=gray>… and '.(count($lines) - 20).' more</>');
        }
    }
}
