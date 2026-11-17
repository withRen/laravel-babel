<?php

namespace App\Models;

use Database\Factories\AdresseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une page (ou un livre entier, quand `page` est nul) d'une bibliothèque.
 * Aucun texte de page en base : le moteur le recalcule depuis l'adresse.
 */
#[Fillable(['bibliotheque_id', 'hexagone', 'mur', 'etagere', 'volume', 'page', 'texte_banni_id'])]
class Adresse extends Model
{
    /** @use HasFactory<AdresseFactory> */
    use HasFactory;

    public const CREATED_AT = 'cree_le';

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        // Les deux empreintes se déduisent toujours des coordonnées : on ne les saisit pas.
        static::saving(function (self $a) {
            $a->empreinte = hash('sha256', $a->ecriture());
            $a->empreinte_livre = hash('sha256', $a->ecritureLivre());
        });
    }

    protected function casts(): array
    {
        return [
            'mur' => 'integer',
            'etagere' => 'integer',
            'volume' => 'integer',
            'page' => 'integer',
            'cree_le' => 'datetime',
        ];
    }

    /** Adresse du livre : `<hexagone>-w<mur>-s<étagère>-v<volume>`. */
    public function ecritureLivre(): string
    {
        return "{$this->hexagone}-w{$this->mur}-s{$this->etagere}-v{$this->volume}";
    }

    /** Adresse écrite sous sa forme valide, avec `:<page>` pour une page. */
    public function ecriture(): string
    {
        return $this->ecritureLivre().($this->page === null ? '' : ":{$this->page}");
    }

    public function estLivre(): bool
    {
        return $this->page === null;
    }

    /** @return BelongsTo<Bibliotheque, $this> */
    public function bibliotheque(): BelongsTo
    {
        return $this->belongsTo(Bibliotheque::class);
    }

    /** @return BelongsTo<TexteBanni, $this> */
    public function texteBanni(): BelongsTo
    {
        return $this->belongsTo(TexteBanni::class, 'texte_banni_id');
    }

    /** @return HasMany<MarquePage, $this> */
    public function marquePages(): HasMany
    {
        return $this->hasMany(MarquePage::class);
    }

    /** @return HasMany<Annotation, $this> */
    public function annotations(): HasMany
    {
        return $this->hasMany(Annotation::class);
    }

    /** @return HasMany<Vote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class);
    }

    /** @return HasMany<LienCourt, $this> */
    public function liensCourts(): HasMany
    {
        return $this->hasMany(LienCourt::class);
    }
}
