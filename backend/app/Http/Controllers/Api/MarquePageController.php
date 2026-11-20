<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ErreurApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListeMarquePagesRequest;
use App\Http\Requests\Api\MarquePageRequest;
use App\Http\Requests\Api\ModificationMarquePageRequest;
use App\Http\Resources\Api\ListePaginee;
use App\Http\Resources\Api\MarquePageResource;
use App\Models\MarquePage;
use App\Services\Membre\Adresses;
use App\Services\Membre\Possession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** /app/moi/marque-pages (M2, M3, M13) : les pages que le membre garde, d'ici ou d'une autre bibliothèque. */
class MarquePageController extends Controller
{
    public function __construct(private readonly Adresses $adresses) {}

    public function index(ListeMarquePagesRequest $requete): JsonResponse
    {
        $marquePages = $requete->user()->marquePages()->with('adresse.bibliotheque')
            ->orderByDesc('cree_le')->orderByDesc('id');

        if ($requete->filled('bibliotheque')) {
            $identifiant = $requete->validated('bibliotheque');
            $marquePages->whereHas('adresse.bibliotheque', fn (Builder $b) => $b->where('identifiant', $identifiant));
        }

        if ($requete->filled('livre')) {
            // Une même adresse de livre existe dans chaque bibliothèque, avec un texte différent : sans
            // « bibliotheque » on parle du livre d'ici, comme partout ailleurs dans l'API.
            $livre = $requete->validated('livre');
            $this->adresses->verifierLivre($livre);
            $identifiant = $requete->validated('bibliotheque') ?? $this->adresses->locale()->identifiant;
            $marquePages->whereHas('adresse', fn (Builder $a) => $a
                ->where('empreinte_livre', hash('sha256', $livre))
                ->whereHas('bibliotheque', fn (Builder $b) => $b->where('identifiant', $identifiant)));
        }

        return response()->json(ListePaginee::de(
            $marquePages->paginate($requete->taille(), ['*'], 'lot', $requete->lot()),
            MarquePageResource::class,
        ));
    }

    public function store(MarquePageRequest $requete): JsonResponse
    {
        $adresse = $this->adresses->trouverOuCreer($requete->validated('bibliotheque'), $requete->validated('adresse'));

        // Règle 7 : une page ne se garde qu'une fois (409). On regarde d'abord, et la base tranche
        // si deux requêtes identiques se croisent.
        $doublon = fn () => ErreurApi::dejaExistant('adresse', 'Cette page est déjà dans vos marque-pages.');
        if ($requete->user()->marquePages()->where('adresse_id', $adresse->id)->exists()) {
            throw $doublon();
        }

        try {
            $marquePage = $requete->user()->marquePages()->create([
                'adresse_id' => $adresse->id,
                'titre' => $requete->validated('titre'),
                'a_convertir' => false,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw $doublon();
        }

        return (new MarquePageResource($marquePage->setRelation('adresse', $adresse)))
            ->response()->setStatusCode(201);
    }

    public function update(ModificationMarquePageRequest $requete, string $id): MarquePageResource
    {
        /** @var MarquePage $marquePage */
        $marquePage = Possession::trouver($requete->user()->marquePages()->with('adresse.bibliotheque'), $id);
        $marquePage->update(['titre' => $requete->validated('titre')]);

        return new MarquePageResource($marquePage);
    }

    public function destroy(Request $requete, string $id): Response
    {
        Possession::trouver($requete->user()->marquePages(), $id)->delete();

        return response()->noContent();
    }
}
