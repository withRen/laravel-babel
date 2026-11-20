<?php

namespace App\Providers;

use App\Services\Annuaire\Annuaire;
use App\Services\Annuaire\AnnuaireHttp;
use App\Models\User;
use App\Services\Engine\Bibliotheque;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
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

        RateLimiter::for('reseau', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
    }
}
