<?php

namespace App\Filament\Resources\TextesBannis\Pages;

use App\Filament\Resources\TextesBannis\TexteBanniResource;
use App\Services\Moderation\ServiceBannissement;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use InvalidArgumentException;

class ListTextesBannis extends ListRecords
{
    protected static string $resource = TexteBanniResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('declarer')->label('Déclarer un texte banni')
                ->schema([
                    Textarea::make('texte')->label('Texte')->required()->rows(4)->maxLength(3200)
                        ->helperText('Il sera normalisé (minuscules, sans accents, 29 symboles) avant d\'être cherché dans les pages.'),
                    Textarea::make('motif')->label('Motif')->rows(2)->maxLength(500),
                ])
                ->action(function (array $data) {
                    try {
                        app(ServiceBannissement::class)->declarer($data['texte'], $data['motif'] ?? null);
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->title('Texte refusé')->body($e->getMessage())->danger()->send();

                        return;
                    }
                    Notification::make()->title('Texte banni et appliqué')->success()->send();
                }),
        ];
    }
}
