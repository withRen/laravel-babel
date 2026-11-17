<?php

namespace App\Providers;

use App\Services\Engine\Bibliotheque;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
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
        RateLimiter::for('reseau', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
    }
}
