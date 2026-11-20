<?php

namespace App\Providers;

use App\Models\Reglage;
use App\Models\User;
use App\Services\Annuaire\Annuaire;
use App\Services\Annuaire\AnnuaireHttp;
use App\Services\Engine\Bibliotheque;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Les dimensions de la bibliothèque (N = 29^3200 pages) : un calcul GMP fait une seule fois par
        // processus, puis partagé par le validateur et le tirage au hasard.
        $this->app->singleton(Bibliotheque::class);

        // L'annuaire est derrière une interface : son format exact n'est pas encore connu (voir AnnuaireHttp).
        $this->app->bind(Annuaire::class, AnnuaireHttp::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Hors production, une relation chargée « à la volée » (N+1) lève une exception :
        // on force l'eager loading (with()) dès le développement.
        // Le contrat renvoie l'objet à la racine ({"identifiant": …}), pas enveloppé dans {"data": …}.
        JsonResource::withoutWrapping();

        Model::preventLazyLoading(! $this->app->isProduction());

        // Derrière ngrok, tous les pairs arrivent de l'adresse du proxy : sans cela, $request->ip() vaudrait la même
        // adresse pour tous et les 60 requêtes/minute (A10) seraient PARTAGÉES entre les 9 pairs. On croit donc
        // X-Forwarded-For, mais seulement quand la connexion vient d'un proxy de confiance (config babel.trusted_proxies,
        // la boucle locale par défaut : l'agent ngrok tourne sur la même machine). Un client direct qui écrit lui-même
        // X-Forwarded-For n'est pas cru. Symfony lit la liste par la droite et saute les proxies de confiance : la
        // dernière adresse ajoutée par NOTRE proxy compte, pas celle qu'un client a pu inscrire au début.
        $proxies = config('babel.trusted_proxies', []);
        TrustProxies::at(in_array('*', $proxies, true) ? '*' : $proxies);

        // Limite de débit de la porte réseau (A10) : le contrat exige d'accepter AU MOINS
        // 60 requêtes par minute et par adresse IP appelante. Au-delà, le rendu 429 du
        // contrat (ERR_TOO_MANY_REQUESTS + Retry-After) est produit par RenduErreursApi.
        // UNE SEULE définition de « administrateur » : cette Gate. Le back-office Filament
        // (User::canAccessPanel) et toute route ou policy (can:administrer, $this->authorize) la réutilisent.
        // Un admin suspendu n'administre plus rien.
        Gate::define('administrer', fn (User $user): bool => $user->estAdmin() && ! $user->estSuspendu());

        // Connexion : 5 essais par minute pour un même couple (email, IP). Freine le « devinage » de mots de passe
        // sans bloquer un autre membre derrière la même IP ; l'email est normalisé pour ne pas contourner la limite
        // en changeant la casse. Le rendu 429 du contrat est produit par RenduErreursApi.
        RateLimiter::for('connexion', function (Request $request) {
            $email = $request->input('email');

            return Limit::perMinute(5)->by((is_string($email) ? mb_strtolower($email) : '').'|'.$request->ip());
        });

        // Inscription : 10 créations par minute et par IP (anti-bots).
        RateLimiter::for('inscription', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // Quotas lus dans les réglages, modifiables par l'administrateur sans redéployer.
        RateLimiter::for('reseau', fn (Request $request) => Limit::perMinute(Reglage::quota('quota_api_publique'))->by($request->ip()));

        // Porte application, lecture (pages, recherche, hasard, ouverture d'un lien) : 120 par minute par défaut. Un membre
        // est compté par son compte (deux membres derrière la même box ne se gênent pas), un visiteur par son IP.
        RateLimiter::for('lecture', fn (Request $request) => Limit::perMinute(Reglage::quota('quota_lecture'))->by(self::cleDeQuota($request)));

        // Publier (créer un lien court, signaler) : 20 par minute par défaut, ouvert aux visiteurs donc plus serré (anti-spam).
        RateLimiter::for('publication', fn (Request $request) => Limit::perMinute(Reglage::quota('quota_publication'))->by(self::cleDeQuota($request)));

        // Recherche sur tout le réseau (V15) : une seule requête de l'application en déclenche une vers CHAQUE pair, donc
        // le quota est plus serré (10 par minute par défaut). Même clé que les autres : le compte du membre, sinon l'IP.
        // Seul le lancement (POST) est limité ; lire l'état d'une recherche reste sous « lecture ».
        RateLimiter::for('recherche-reseau', fn (Request $request) => Limit::perMinute(Reglage::quota('quota_recherche_reseau'))->by(self::cleDeQuota($request)));
    }

    /** Un membre reconnu compte par son identifiant, un visiteur par son adresse IP. */
    private static function cleDeQuota(Request $request): string
    {
        // Le limiteur passe AVANT le middleware « auth.optionnelle » (Laravel trie les middlewares par priorité) :
        // $request->user() est donc vide à ce stade. On lit le jeton nous-mêmes, sinon un membre serait compté par son IP.
        $membre = $request->user() ?? ($request->bearerToken() !== null ? Auth::guard('sanctum')->user() : null);

        return $membre !== null ? 'membre:'.$membre->getAuthIdentifier() : 'ip:'.$request->ip();
    }
}
