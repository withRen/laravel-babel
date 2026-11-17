<?php

namespace App\Models;

use App\Enums\ModeRecherche;
use App\Enums\PorteeRecherche;
use Database\Factories\RechercheFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Recherche d'un membre. L'adresse trouvée n'est pas stockée : la même recherche la redonne. */
#[Fillable(['utilisateur_id', 'texte', 'mode', 'portee'])]
class Recherche extends Model
{
    /** @use HasFactory<RechercheFactory> */
    use HasFactory;

    public const CREATED_AT = 'cree_le';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'mode' => ModeRecherche::class,
            'portee' => PorteeRecherche::class,
            'cree_le' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilisateur_id');
    }
}
