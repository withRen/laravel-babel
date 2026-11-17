<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Hors production, une relation chargée « à la volée » (N+1) lève une exception :
        // on force l'eager loading (with()) dès le développement.
        Model::preventLazyLoading(! $this->app->isProduction());
    }
}
