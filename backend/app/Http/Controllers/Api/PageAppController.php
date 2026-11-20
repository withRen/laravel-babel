<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Activite\Compteur;
use App\Services\Lecture\ServiceLecture;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /app/pages/{adresse}?bibliotheque= (V1, V15, V16) : le texte de la page et tout ce qui l'entoure
 * (annotations visibles, score, vote et marque-page du membre, adresse du livre).
 */
class PageAppController extends Controller
{
    public function __invoke(string $adresse, Request $requete, ServiceLecture $lecture, Compteur $compteur): JsonResponse
    {
        $bibliotheque = $lecture->bibliotheque($requete->query('bibliotheque'));

        // Adresse contrôlée avant tout appel réseau ; 404 / 422 (moteur), 502 / 503 (pair).
        $texte = $lecture->texte($bibliotheque, $adresse);

        // Statistiques (A11) : seulement les pages lues chez nous, et seulement un compteur.
        if ($bibliotheque->est_locale) {
            $compteur->incrementer(Compteur::PAGES_LUES);
        }

        return response()->json($lecture->pageLue($bibliotheque, $adresse, $texte, $requete->user()));
    }
}
