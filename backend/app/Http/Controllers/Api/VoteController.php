<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListePagineeRequest;
use App\Http\Requests\Api\VoteRequest;
use App\Http\Resources\Api\ListePaginee as Liste;
use App\Http\Resources\Api\VoteResource;
use App\Models\Vote;
use App\Services\Membre\Adresses;
use App\Services\Membre\ScoreDePage;
use Illuminate\Http\JsonResponse;

/**
 * Les votes (M7) : PUT et DELETE /app/pages/{adresse}/vote, et la liste GET /app/moi/votes.
 * Un seul vote par membre et par page (règle 10) : voter une deuxième fois REMPLACE le premier.
 */
class VoteController extends Controller
{
    public function __construct(private readonly Adresses $adresses) {}

    public function index(ListePagineeRequest $requete): JsonResponse
    {
        $votes = Vote::query()
            ->where('utilisateur_id', $requete->user()->id)
            ->with('adresse.bibliotheque')
            ->orderByDesc('cree_le')->orderByDesc('adresse_id');

        return response()->json(Liste::de(
            $votes->paginate($requete->taille(), ['*'], 'lot', $requete->lot()),
            VoteResource::class,
        ));
    }

    public function voter(VoteRequest $requete, string $adresse): JsonResponse
    {
        $page = $this->adresses->trouverOuCreer($requete->validated('bibliotheque'), $adresse);
        $valeur = $requete->integer('valeur');

        // « upsert » : insère ou remplace en une seule instruction, sans course possible entre deux requêtes.
        // La date est celle du vote en vigueur : la liste « du plus récent au plus ancien » suit les changements d'avis.
        Vote::query()->upsert(
            [['utilisateur_id' => $requete->user()->id, 'adresse_id' => $page->id, 'valeur' => $valeur, 'cree_le' => now()]],
            ['utilisateur_id', 'adresse_id'],
            ['valeur', 'cree_le'],
        );

        return $this->etat($page->bibliotheque->identifiant, $page->ecriture(), $valeur, $page->id);
    }

    public function retirer(VoteRequest $requete, string $adresse): JsonResponse
    {
        // On ne crée pas la ligne ADRESSE pour retirer un vote : sans elle, il n'y a pas de vote à retirer
        // (même réponse qu'avec une page jamais votée).
        $page = $this->adresses->trouver($requete->validated('bibliotheque'), $adresse);

        if ($page !== null) {
            Vote::query()->where('utilisateur_id', $requete->user()->id)->where('adresse_id', $page->id)->delete();
        }

        return $this->etat(
            $this->adresses->bibliotheque($requete->validated('bibliotheque'))->identifiant,
            $adresse,
            null,
            $page?->id,
        );
    }

    private function etat(string $bibliotheque, string $adresse, ?int $monVote, ?int $adresseId): JsonResponse
    {
        return response()->json([
            'bibliotheque' => $bibliotheque,
            'adresse' => $adresse,
            'mon_vote' => $monVote,
            'score' => ScoreDePage::de($adresseId),
        ]);
    }
}
