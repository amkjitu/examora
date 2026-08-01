<?php

namespace App\Filament\Pages;

use App\Models\Examora\Exam;
use App\Services\Prediction\NearDuplicateDetector;
use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

/**
 * Questions that look like the same question reworded across sittings.
 *
 * Nothing here is merged. The exact fingerprint stays the source of truth for
 * `past_questions`, because a false merge would unify two questions with
 * different answer keys and nobody would notice. This page exists so a human can
 * confirm what the embeddings suspect.
 */
class SuspectedDuplicates extends Page
{
    protected static BackedEnum|string|null $navigationIcon = 'phosphor-copy-duotone';

    protected static ?string $navigationLabel = 'Suspected duplicates';

    protected static UnitEnum|string|null $navigationGroup = 'Examora';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.suspected-duplicates';

    public ?int $examId = null;

    public function mount(): void
    {
        $this->examId ??= Exam::where('is_active', true)->value('id');
    }

    public function getExams(): array
    {
        return Exam::where('is_active', true)->pluck('name', 'id')->all();
    }

    public function getClusters(): array
    {
        if (! $this->examId) {
            return [];
        }

        $exam = Exam::find($this->examId);

        return $exam
            ? app(NearDuplicateDetector::class)->clusters($exam)->all()
            : [];
    }

    public function getThreshold(): float
    {
        return NearDuplicateDetector::THRESHOLD;
    }
}
