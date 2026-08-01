<?php

namespace App\Filament\Resources\ExtractedQuestions\Pages;

use App\Filament\Resources\ExtractedQuestions\ExtractedQuestionResource;
use Filament\Resources\Pages\ListRecords;

class ListExtractedQuestions extends ListRecords
{
    protected static string $resource = ExtractedQuestionResource::class;

    // No create action: rows here come from the pipeline, not from a form.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
