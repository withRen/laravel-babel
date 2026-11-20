<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Vote d'un membre sur une page (+1 ou -1). Pivot entre User et Adresse : Eloquent gère
 * ainsi la clé composite (utilisateur_id, adresse_id) pour les mises à jour.
 */
#[Fillable(['utilisateur_id', 'adresse_id', 'valeur'])]
class Vote extends Pivot
{
    public const CREATED_AT = 'cree_le';

    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $table = 'votes';

    protected $foreignKey = 'utilisateur_id';

    protected $relatedKey = 'adresse_id';

    protected function casts(): array
    {
        return ['valeur' => 'integer', 'cree_le' => 'datetime'];
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
}
