<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

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

    /** Quotas de l'API par minute et par appelant (A10) : clé de réglage => valeur par défaut. */
    public const QUOTAS = [
        'quota_api_publique' => 60,  // porte réseau /api/v1 : le contrat exige d'accepter au moins 60
        'quota_lecture' => 120,      // porte application : lectures
        'quota_publication' => 20,   // porte application : publications
    ];

    /** Plancher du contrat : la porte réseau doit accepter AU MOINS 60 requêtes par minute et par IP. */
    public const QUOTA_RESEAU_MINIMUM = 60;

    /**
     * Le quota par minute d'un réglage de QUOTAS. Lu en base (A10), gardé 30 s en cache : le limiteur
     * s'exécute à chaque requête, il ne doit pas coûter une requête SQL à chaque fois. Une valeur absente,
     * non numérique ou < 1 retombe sur la valeur par défaut (un quota de 0 bloquerait tout le monde).
     */
    public static function quota(string $cle): int
    {
        $defaut = self::QUOTAS[$cle] ?? 60;

        $brut = Cache::remember('reglage.'.$cle, 30, fn () => static::valeur($cle));

        $quota = is_string($brut) && ctype_digit($brut) && (int) $brut >= 1 ? (int) $brut : $defaut;

        return $cle === 'quota_api_publique' ? max($quota, self::QUOTA_RESEAU_MINIMUM) : $quota;
    }

    /** Écrit un réglage et vide son cache : le changement vaut tout de suite. */
    public static function definir(string $cle, string $valeur): void
    {
        static::query()->updateOrCreate(['cle' => $cle], ['valeur' => $valeur]);
        Cache::forget('reglage.'.$cle);
    }
}
