<?php

namespace App\Filament\Resources\Reseau\Pages;

use App\Filament\Resources\Reseau\BibliothequeResource;
use App\Filament\Widgets\EtatReseauStats;
use Filament\Resources\Pages\ListRecords;

class ListBibliotheques extends ListRecords
{
    protected static string $resource = BibliothequeResource::class;

    protected function getHeaderWidgets(): array
    {
        return [EtatReseauStats::class];
    }
}
