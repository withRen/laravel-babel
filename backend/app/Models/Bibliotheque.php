<?php

namespace App\Models;

use App\Enums\EtatBibliotheque;
use Database\Factories\BibliothequeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Une bibliothèque du réseau. La nôtre est la ligne `est_locale`. Jamais la clé : seulement son empreinte. */
#[Fillable(['identifiant', 'nom', 'description', 'url_actuelle', 'est_locale', 'etat', 'echecs_consecutifs',
    'dernier_contact_le', 'vue_annuaire_le', 'empreinte_cle', 'cle_changee_le'])]
class Bibliotheque extends Model
{
    /** @use HasFactory<BibliothequeFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'est_locale' => 'boolean',
            'etat' => EtatBibliotheque::class,
            'echecs_consecutifs' => 'integer',
            'dernier_contact_le' => 'datetime',
            'vue_annuaire_le' => 'datetime',
            'cle_changee_le' => 'datetime',
        ];
    }

    public static function locale(): ?self
    {
        return static::query()->where('est_locale', true)->first();
    }

    /** @return HasMany<Adresse, $this> */
    public function adresses(): HasMany
    {
        return $this->hasMany(Adresse::class);
    }

    /** @return HasMany<TexteBanni, $this> */
    public function textesBannis(): HasMany
    {
        return $this->hasMany(TexteBanni::class, 'origine_id');
    }
}
