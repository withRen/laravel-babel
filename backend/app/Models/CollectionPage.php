<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une page à une position d'une collection. Clé composite (collection_id, position) :
 * Eloquent ne sait pas la mettre à jour, on supprime et on recrée (réordonner = réécrire).
 */
#[Table('collection_pages')]
#[Fillable(['collection_id', 'position', 'adresse_id'])]
class CollectionPage extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected function casts(): array
    {
        return ['position' => 'integer'];
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
}
