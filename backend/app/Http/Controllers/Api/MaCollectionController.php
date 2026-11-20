<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ErreurApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CollectionMembreRequest;
use App\Http\Requests\Api\ListePagineeRequest;
use App\Http\Requests\Api\OrdrePagesRequest;
use App\Http\Requests\Api\PageDeCollectionRequest;
use App\Http\Resources\Api\ListePaginee;
use App\Http\Resources\Api\MaCollectionResource;
use App\Http\Resources\Api\ResumeMaCollectionResource;
use App\Models\Collection;
use App\Models\User;
use App\Services\Membre\Adresses;
use App\Services\Membre\PagesDeCollection;
use App\Services\Membre\Possession;
use App\Services\Membre\VerificateurDeTextesBannis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * /app/moi/collections (M4, M5, M13) : les collections du membre, publiques ou privées, et leurs pages ordonnées.
 * Toutes les routes retrouvent la collection parmi CELLES DU MEMBRE : celle d'un autre répond 404.
 */
class MaCollectionController extends Controller
{
    public function __construct(
        private readonly Adresses $adresses,
        private readonly PagesDeCollection $pages,
        private readonly VerificateurDeTextesBannis $verificateur,
    ) {}

    public function index(ListePagineeRequest $requete): JsonResponse
    {
        $collections = $requete->user()->collections()
            // reorder() : la relation trie ses pages, ce qui n'a pas de sens (et gêne certaines bases) dans un simple compte.
            ->withCount(['pages as nombre_pages' => fn ($pages) => $pages->reorder()])
            ->orderByDesc('maj_le')->orderByDesc('id');

        return response()->json(ListePaginee::de(
            $collections->paginate($requete->taille(), ['*'], 'lot', $requete->lot()),
            ResumeMaCollectionResource::class,
        ));
    }

    public function store(CollectionMembreRequest $requete): JsonResponse
    {
        $collection = $requete->user()->collections()->create([
            'nom' => $requete->validated('nom'),
            'description' => $requete->validated('description') ?? '',
            'est_publique' => $requete->validated('est_publique') ?? false, // privée par défaut (M5)
        ]);

        return (new MaCollectionResource($this->recharger($requete->user(), $collection->id)))
            ->response()->setStatusCode(201);
    }

    public function show(Request $requete, string $id): MaCollectionResource
    {
        return new MaCollectionResource($this->trouver($requete->user(), $id));
    }

    public function update(CollectionMembreRequest $requete, string $id): MaCollectionResource
    {
        $collection = $this->trouver($requete->user(), $id);
        $etaitPublique = $collection->est_publique;

        $collection->fill($requete->validated());
        $collection->description ??= '';
        $collection->save();

        // La collection devient publique : ses pages le deviennent aussi, on les contrôle (comme à l'ajout).
        // Seulement celles d'ici : lire des dizaines de pages chez d'autres bibliothèques bloquerait la requête
        // (5 secondes par pair éteint) ; la tâche de fond de modération s'en charge.
        if (! $etaitPublique && $collection->est_publique) {
            foreach ($collection->pages as $page) {
                if ($page->adresse->bibliotheque->est_locale) {
                    $this->verificateur->verifier($page->adresse);
                }
            }
        }

        return new MaCollectionResource($collection);
    }

    public function destroy(Request $requete, string $id): Response
    {
        // Les liens courts et les signalements qui visent la collection partent avec elle (clés étrangères en cascade).
        Possession::trouver($requete->user()->collections(), $id)->delete();

        return response()->noContent();
    }

    /** M4, M13 : ajoute une page, à la fin ou à la position demandée (les suivantes sont décalées). */
    public function ajouterPage(PageDeCollectionRequest $requete, string $id): MaCollectionResource
    {
        $collection = $this->trouver($requete->user(), $id);
        $adresse = $this->adresses->trouverOuCreer($requete->validated('bibliotheque'), $requete->validated('adresse'));

        $lignes = $this->pages->lignes($collection);
        $position = $requete->validated('position') ?? count($lignes) + 1;

        if ($position > count($lignes) + 1) {
            throw ErreurApi::une(
                422,
                'ERR_INVALID_PARAMETER',
                'La position doit être comprise entre 1 et '.(count($lignes) + 1).'.',
                'position',
            );
        }

        array_splice($lignes, $position - 1, 0, [['adresse_id' => $adresse->id, 'a_convertir' => false]]);
        $this->pages->ecrire($collection, $lignes);

        if ($collection->est_publique) {
            $this->verificateur->verifier($adresse);
        }

        return new MaCollectionResource($this->recharger($requete->user(), $collection->id));
    }

    /** M4 : remplace la liste des pages par celle envoyée, dans cet ordre (glisser-déposer). */
    public function ordonnerPages(OrdrePagesRequest $requete, string $id): MaCollectionResource
    {
        $collection = $this->trouver($requete->user(), $id);

        DB::transaction(function () use ($requete, $collection): void {
            // Une page qui était déjà là garde son état « à convertir » ; une page ajoutée par la même occasion non.
            $aConvertir = [];
            foreach ($this->pages->lignes($collection) as $ligne) {
                $aConvertir[$ligne['adresse_id']][] = $ligne['a_convertir'];
            }

            $lignes = [];
            $nouvelles = [];
            foreach ($requete->validated('pages') as $page) {
                $adresse = $this->adresses->trouverOuCreer($page['bibliotheque'] ?? null, $page['adresse']);
                $convertir = ! empty($aConvertir[$adresse->id]) && array_shift($aConvertir[$adresse->id]);
                $lignes[] = ['adresse_id' => $adresse->id, 'a_convertir' => $convertir];
                $nouvelles[$adresse->id] = $adresse;
            }

            $this->pages->ecrire($collection, $lignes);

            if ($collection->est_publique) {
                foreach ($nouvelles as $adresse) {
                    $this->verificateur->verifier($adresse);
                }
            }
        });

        return new MaCollectionResource($this->recharger($requete->user(), $collection->id));
    }

    /** Retire la page à cette position ; les suivantes remontent d'un rang. */
    public function retirerPage(Request $requete, string $id, string $position): MaCollectionResource
    {
        $collection = $this->trouver($requete->user(), $id);
        $lignes = $this->pages->lignes($collection);

        if (! ctype_digit($position) || (int) $position < 1 || (int) $position > count($lignes)) {
            throw Possession::introuvable('position');
        }

        array_splice($lignes, (int) $position - 1, 1);
        $this->pages->ecrire($collection, $lignes);

        return new MaCollectionResource($this->recharger($requete->user(), $collection->id));
    }

    private function trouver(User $membre, string $id): Collection
    {
        /** @var Collection */
        return Possession::trouver($membre->collections()->with('pages.adresse.bibliotheque'), $id);
    }

    /** La collection telle qu'elle est maintenant en base, avec ses pages. */
    private function recharger(User $membre, int $id): Collection
    {
        return $this->trouver($membre, (string) $id);
    }
}
