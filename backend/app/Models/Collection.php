<?php

namespace App\Models;

use Database\Factories\CollectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['utilisateur_id', 'nom', 'description', 'est_publique', 'masque_le'])]
class Collection extends Model
{
    /** @use HasFactory<CollectionFactory> */
    use HasFactory;

    public const CREATED_AT = 'cree_le';

    public const UPDATED_AT = 'maj_le';

    protected function casts(): array
    {
        return [
            'est_publique' => 'boolean',
            'masque_le' => 'datetime',
            'cree_le' => 'datetime',
            'maj_le' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilisateur_id');
    }

    /** @return HasMany<CollectionPage, $this> */
    public function pages(): HasMany
    {
        return $this->hasMany(CollectionPage::class)->orderBy('position');
    }

    /** @return HasMany<LienCourt, $this> */
    public function liensCourts(): HasMany
    {
        return $this->hasMany(LienCourt::class);
    }

    /** @return HasMany<Signalement, $this> */
    public function signalements(): HasMany
    {
        return $this->hasMany(Signalement::class);
    }
}
