<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AnnotationMembreRequest;
use App\Http\Requests\Api\ListePagineeRequest;
use App\Http\Resources\Api\AnnotationMembreResource;
use App\Http\Resources\Api\ListePaginee;
use App\Models\Annotation;
use App\Services\Membre\Adresses;
use App\Services\Membre\Possession;
use App\Services\Membre\VerificateurDeTextesBannis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** /app/moi/annotations (M6) : les annotations du membre, publiques ou privées, sur des pages d'ici ou d'ailleurs. */
class AnnotationMembreController extends Controller
{
    public function __construct(
        private readonly Adresses $adresses,
        private readonly VerificateurDeTextesBannis $verificateur,
    ) {}

    public function index(ListePagineeRequest $requete): JsonResponse
    {
        $annotations = $requete->user()->annotations()
            ->with(['adresse.bibliotheque', 'utilisateur'])
            ->orderByDesc('cree_le')->orderByDesc('id');

        return response()->json(ListePaginee::de(
            $annotations->paginate($requete->taille(), ['*'], 'lot', $requete->lot()),
            AnnotationMembreResource::class,
        ));
    }

    public function store(AnnotationMembreRequest $requete): JsonResponse
    {
        $adresse = $this->adresses->trouverOuCreer($requete->validated('bibliotheque'), $requete->validated('adresse'));

        $annotation = $requete->user()->annotations()->create([
            'adresse_id' => $adresse->id,
            'contenu' => $requete->validated('contenu'),
            'est_publique' => $requete->validated('est_publique'),
            'a_convertir' => false,
        ]);

        // Une annotation PUBLIQUE est vérifiée comme tout ce qui est publié (cahier des charges, règle 18).
        // Le contrôle marque l'adresse ; « masquee » est lu sur elle, juste après.
        if ($annotation->est_publique) {
            $this->verificateur->verifier($adresse);
        }

        $annotation->setRelation('adresse', $adresse)->setRelation('utilisateur', $requete->user());

        return (new AnnotationMembreResource($annotation))->response()->setStatusCode(201);
    }

    public function update(AnnotationMembreRequest $requete, string $id): AnnotationMembreResource
    {
        /** @var Annotation $annotation */
        $annotation = Possession::trouver($requete->user()->annotations()->with('adresse.bibliotheque'), $id);
        $etaitPublique = $annotation->est_publique;

        // La page, elle, ne change pas : pour une autre page, on supprime et on recrée.
        $annotation->update($requete->validated());

        if (! $etaitPublique && $annotation->est_publique) {
            $this->verificateur->verifier($annotation->adresse);
        }

        $annotation->setRelation('utilisateur', $requete->user());

        return new AnnotationMembreResource($annotation);
    }

    public function destroy(Request $requete, string $id): Response
    {
        // Ses signalements partent avec elle (règle 13, clé étrangère en cascade).
        Possession::trouver($requete->user()->annotations(), $id)->delete();

        return response()->noContent();
    }
}
