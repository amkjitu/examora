<?php

namespace App\Console\Commands;

use App\Models\Examora\Exam;
use App\Models\Examora\Topic;
use App\Services\Knowledge\Indexer;
use App\Services\Learning\LessonGenerator;
use App\Services\Learning\TopicImportanceScorer;
use App\Services\Prediction\PredictionEngine;
use Illuminate\Console\Command;

/**
 * One command to take a freshly seeded exam from raw past questions to a
 * study-ready product: index -> score -> generate lessons.
 */
class ExamoraBootstrap extends Command
{
    protected $signature = 'examora:bootstrap
                            {exam? : Exam slug (defaults to config examora.default_exam)}
                            {--skip-lessons : Index and score only}
                            {--force-lessons : Regenerate lessons that already exist}
                            {--limit=0 : Only generate lessons for the N most important topics}';

    protected $description = 'Index past questions, recompute topic importance, forecast the next session, and pre-generate lessons.';

    public function handle(
        Indexer $indexer,
        TopicImportanceScorer $scorer,
        LessonGenerator $generator,
        PredictionEngine $predictor,
    ): int {
        $slug = $this->argument('exam') ?? config('examora.default_exam');
        $exam = Exam::where('slug', $slug)->first();

        if (! $exam) {
            $this->error("Exam [{$slug}] not found. Run `php artisan db:seed --class=BcsPreliminarySeeder` first.");

            return self::FAILURE;
        }

        $this->components->info("Bootstrapping {$exam->name}");

        $this->components->task('Indexing past questions', function () use ($indexer, $exam, &$indexed) {
            $indexed = $indexer->indexPastQuestions($exam->id);

            return true;
        });
        $this->line("  <fg=gray>{$indexed} chunks embedded</>");

        $this->components->task('Scoring topic importance', function () use ($scorer, $exam, &$scored) {
            $scored = $scorer->recomputeExam($exam);

            return true;
        });
        $this->line("  <fg=gray>{$scored} topics scored</>");

        $this->components->task('Forecasting the next session', function () use ($predictor, $exam, &$forecast) {
            $forecast = $predictor->forecast($exam);

            return true;
        });
        $this->line("  <fg=gray>{$forecast} topics forecast</>");

        // Print the honest self-assessment every time, so a degrading model is
        // noticed rather than discovered by a student.
        $backtest = $predictor->backtest($exam);

        if ($backtest['ok']) {
            $verdict = $backtest['brier_score'] < $backtest['baseline_brier'] ? 'better than guessing' : 'NO BETTER THAN GUESSING';
            $this->line("  <fg=gray>backtest on {$backtest['held_out_session']}: Brier {$backtest['brier_score']} vs baseline {$backtest['baseline_brier']} — {$verdict}</>");
            $this->line("  <fg=gray>mean absolute error {$backtest['mean_absolute_error']} questions per topic</>");
        } else {
            $this->line("  <fg=gray>backtest skipped: {$backtest['reason']}</>");
        }

        if ($this->option('skip-lessons')) {
            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');

        $topics = Topic::query()
            ->whereHas('subject', fn ($q) => $q->where('exam_id', $exam->id))
            ->whereHas('pastQuestions')
            ->with(['subject.exam', 'statistics'])
            ->get()
            ->sortByDesc(fn (Topic $t) => $t->statistics?->importance_score ?? 0)
            ->when($limit > 0, fn ($c) => $c->take($limit));

        $bar = $this->output->createProgressBar($topics->count());
        $bar->start();

        foreach ($topics as $topic) {
            try {
                $generator->generate($topic, force: (bool) $this->option('force-lessons'));
            } catch (\Throwable $e) {
                $this->newLine();
                $this->components->warn("Lesson failed for {$topic->name}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->components->info('Ready. '.$topics->count().' lessons available.');

        return self::SUCCESS;
    }
}
