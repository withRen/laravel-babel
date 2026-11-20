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

    /** La valeur d'un réglage, ou $defaut s'il n'existe pas (base pas encore amorcée). */
    public static function valeur(string $cle, ?string $defaut = null): ?string
    {
        return static::query()->whereKey($cle)->value('valeur') ?? $defaut;
    }

    /** A8 : l'administrateur peut fermer les inscriptions. Ouvertes par défaut. */
    public static function inscriptionsOuvertes(): bool
    {
        return static::valeur('inscriptions_ouvertes', '1') === '1';
    }
}
