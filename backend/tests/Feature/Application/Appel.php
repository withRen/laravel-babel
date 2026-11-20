<?php

use App\Models\Adresse;
use App\Models\Bibliotheque;
use App\Models\User;
use App\Services\Lecture\RegistreAdresses;

/**
 * Un appel à la porte application (/api/v1/app), avec ou sans jeton de membre.
 * Chaque appel recrée le garde d'authentification : sans cela, Laravel garderait en mémoire le membre
 * du premier appel du test et un « visiteur » ensuite serait reconnu à tort.
 */
function appel(string $methode, string $chemin, array $donnees = [], ?User $membre = null, array $entetes = [])
{
    app('auth')->forgetGuards();

    if ($membre !== null) {
        $entetes['Authorization'] = 'Bearer '.$membre->createToken('test')->plainTextToken;
    }

    return test()->json($methode, '/api/v1/app'.$chemin, $donnees, $entetes);
}

/** La page 1 de la clé de test A du moteur, celle des exemples du contrat. */
const PAGE_EXEMPLE = '0-w1-s1-v1:1';

const LIVRE_EXEMPLE = '0-w1-s1-v1';

/** Un extrait de PAGE_EXEMPLE (texte « tnznd.olonj xxqvbefublhfm.. »). */
const PASSAGE_EXEMPLE = 'olonj xxqvbefublhf';

/** La ligne `adresses` d'une page ou d'un livre de la bibliothèque donnée. */
function adresseEnregistree(Bibliotheque $bibliotheque, string $adresse = PAGE_EXEMPLE): Adresse
{
    return app(RegistreAdresses::class)->enregistrer($bibliotheque, $adresse);
}
