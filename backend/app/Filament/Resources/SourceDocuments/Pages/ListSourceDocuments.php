<?php

namespace App\Filament\Resources\SourceDocuments\Pages;

use App\Filament\Resources\SourceDocuments\SourceDocumentResource;
use Filament\Resources\Pages\ListRecords;

class ListSourceDocuments extends ListRecords
{
    protected static string $resource = SourceDocumentResource::class;

    // Documents arrive by upload, not by an admin form.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
