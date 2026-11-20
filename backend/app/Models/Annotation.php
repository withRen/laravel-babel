<?php

namespace App\Models;

use Database\Factories\AnnotationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['utilisateur_id', 'adresse_id', 'contenu', 'est_publique', 'masque_le', 'a_convertir'])]
class Annotation extends Model
{
    /** @use HasFactory<AnnotationFactory> */
    use HasFactory;

    public const CREATED_AT = 'cree_le';

    public const UPDATED_AT = 'maj_le';

    protected function casts(): array
    {
        return [
            'est_publique' => 'boolean',
            'a_convertir' => 'boolean',
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

    /** @return BelongsTo<Adresse, $this> */
    public function adresse(): BelongsTo
    {
        return $this->belongsTo(Adresse::class);
    }

    /** @return HasMany<Signalement, $this> */
    public function signalements(): HasMany
    {
        return $this->hasMany(Signalement::class);
    }
}
