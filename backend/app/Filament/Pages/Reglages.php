<?php

namespace App\Filament\Pages;

use App\Models\Bibliotheque;
use App\Models\Reglage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * A8 (nom, description, inscriptions ouvertes ou fermées) et A10 (quotas de l'API).
 * Le nom et la description sont sur la ligne locale de `bibliotheques` ; le reste dans `reglages` (règle 22).
 * La clé du moteur et le jeton réseau n'apparaissent jamais ici : ils sont dans la configuration du serveur.
 */
class Reglages extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Réglages';

    protected static ?string $title = 'Réglages de la bibliothèque';

    protected static ?int $navigationSort = 50;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $locale = Bibliotheque::locale();

        $this->form->fill([
            'nom' => $locale?->nom,
            'description' => $locale?->description,
            'inscriptions_ouvertes' => Reglage::inscriptionsOuvertes(),
            'quota_api_publique' => Reglage::quota('quota_api_publique'),
            'quota_lecture' => Reglage::quota('quota_lecture'),
            'quota_publication' => Reglage::quota('quota_publication'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Identité')->description('Ce que les autres bibliothèques voient dans votre identité (B6).')->schema([
                TextInput::make('nom')->label('Nom')->required()->maxLength(100),
                Textarea::make('description')->label('Description')->rows(3)->maxLength(500),
            ]),
            Section::make('Inscriptions')->schema([
                Toggle::make('inscriptions_ouvertes')->label('Inscriptions ouvertes')
                    ->helperText('Fermées, l\'inscription répond 403. Les membres déjà inscrits ne sont pas touchés.'),
            ]),
            Section::make('Quotas de l\'API (requêtes par minute et par appelant)')->schema([
                TextInput::make('quota_api_publique')->label('Porte réseau (/api/v1)')->numeric()->integer()->required()
                    ->minValue(Reglage::QUOTA_RESEAU_MINIMUM)->maxValue(100000)
                    ->helperText('Le contrat impose d\'accepter au moins '.Reglage::QUOTA_RESEAU_MINIMUM.' requêtes par minute et par IP.'),
                TextInput::make('quota_lecture')->label('Application : lectures')->numeric()->integer()->required()->minValue(1)->maxValue(100000),
                TextInput::make('quota_publication')->label('Application : publications')->numeric()->integer()->required()->minValue(1)->maxValue(100000),
            ])->columns(3),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label('Enregistrer')->submit('save')])]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        Bibliotheque::locale()?->forceFill(['nom' => $data['nom'], 'description' => $data['description'] ?? null])->save();
        Reglage::definir('inscriptions_ouvertes', $data['inscriptions_ouvertes'] ? '1' : '0');

        foreach (array_keys(Reglage::QUOTAS) as $cle) {
            Reglage::definir($cle, (string) (int) $data[$cle]);
        }

        Notification::make()->title('Réglages enregistrés')->success()->send();
    }
}
