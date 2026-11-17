<?php

namespace App\Models;

use App\Enums\StatutTexteBanni;
use Database\Factories\TexteBanniFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Texte banni, stocké en clair et normalisé ; l'empreinte (SHA-256) sert à éviter les doublons. */
#[Table('textes_bannis')]
#[Fillable(['texte', 'empreinte', 'motif', 'origine_id', 'statut', 'decide_le'])]
class TexteBanni extends Model
{
    /** @use HasFactory<TexteBanniFactory> */
    use HasFactory;

    public const CREATED_AT = 'cree_le';

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::saving(function (self $t) {
            if (blank($t->empreinte)) {
                $t->empreinte = hash('sha256', $t->texte);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'statut' => StatutTexteBanni::class,
            'decide_le' => 'datetime',
            'cree_le' => 'datetime',
        ];
    }

    /** @return BelongsTo<Bibliotheque, $this> */
    public function origine(): BelongsTo
    {
        return $this->belongsTo(Bibliotheque::class, 'origine_id');
    }

    /** @return HasMany<Adresse, $this> */
    public function adresses(): HasMany
    {
        return $this->hasMany(Adresse::class, 'texte_banni_id');
    }
}
