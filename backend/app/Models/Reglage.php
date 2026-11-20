<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/** Paire clé/valeur de réglage. Jamais la clé du moteur ni le jeton réseau (configuration du serveur). */
#[Table('reglages')]
#[Fillable(['cle', 'valeur'])]
class Reglage extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'cle';

    protected $keyType = 'string';
}
