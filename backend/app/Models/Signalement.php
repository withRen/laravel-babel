<?php

namespace App\Models;

use App\Enums\StatutSignalement;
use Database\Factories\SignalementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Vise exactement un contenu publié : annotation, collection ou lien court (CHECK en base). */
#[Fillable(['annotation_id', 'collection_id', 'lien_court_id', 'auteur_id', 'motif', 'statut', 'traite_par_id', 'traite_le'])]
class Signalement extends Model
{
    /** @use HasFactory<SignalementFactory> */
    use HasFactory;

    public const CREATED_AT = 'cree_le';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'statut' => StatutSignalement::class,
            'traite_le' => 'datetime',
            'cree_le' => 'datetime',
        ];
    }

    /** @return BelongsTo<Annotation, $this> */
    public function annotation(): BelongsTo
    {
        return $this->belongsTo(Annotation::class);
    }

    /** @return BelongsTo<Collection, $this> */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    /** @return BelongsTo<LienCourt, $this> */
    public function lienCourt(): BelongsTo
    {
        return $this->belongsTo(LienCourt::class, 'lien_court_id');
    }

    /** @return BelongsTo<User, $this> */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }

    /** @return BelongsTo<User, $this> */
    public function traitePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'traite_par_id');
    }
}
