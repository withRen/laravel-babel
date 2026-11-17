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
}
