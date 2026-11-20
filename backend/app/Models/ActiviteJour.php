<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/** Compteurs anonymes par jour (clé : la date). */
#[Table('activites_jour')]
#[Fillable(['jour', 'pages_lues', 'recherches'])]
class ActiviteJour extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'jour';

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['jour' => 'date:Y-m-d', 'pages_lues' => 'integer', 'recherches' => 'integer'];
    }

    /**
     * A11 : ajoute 1 au compteur du jour (« pages_lues » ou « recherches »). Une seule ligne par jour, jamais une ligne
     * par lecture (règle 21 : pas de données personnelles). Atomique : deux requêtes en même temps ne perdent pas de compte.
     */
    public static function compter(string $compteur): void
    {
        if (! in_array($compteur, ['pages_lues', 'recherches'], true)) {
            throw new \InvalidArgumentException("Compteur inconnu : $compteur");
        }

        $jour = now()->toDateString();

        static::query()->insertOrIgnore(['jour' => $jour]);
        static::query()->whereKey($jour)->increment($compteur);
    }
}
