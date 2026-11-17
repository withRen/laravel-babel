<?php

namespace App\Models;

use Database\Factories\LienCourtFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Exactement une cible : une adresse (page ou livre) ou une collection (CHECK en base). */
#[Table('liens_courts')]
#[Fillable(['slug', 'utilisateur_id', 'collection_id', 'adresse_id', 'passage', 'visites', 'expire_le', 'masque_le'])]
class LienCourt extends Model
{
    /** @use HasFactory<LienCourtFactory> */
    use HasFactory;

    public const CREATED_AT = 'cree_le';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'visites' => 'integer',
            'expire_le' => 'datetime',
            'masque_le' => 'datetime',
            'cree_le' => 'datetime',
        ];
    }

    public function estExpire(): bool
    {
        return $this->expire_le !== null && $this->expire_le->isPast();
    }

    /** @return BelongsTo<User, $this> */
    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilisateur_id');
    }

    /** @return BelongsTo<Collection, $this> */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    /** @return BelongsTo<Adresse, $this> */
    public function adresse(): BelongsTo
    {
        return $this->belongsTo(Adresse::class);
    }

    /** @return HasMany<Signalement, $this> */
    public function signalements(): HasMany
    {
        return $this->hasMany(Signalement::class, 'lien_court_id');
    }
}
