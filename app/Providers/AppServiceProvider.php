<?php

namespace App\Providers;

use App\Features\ModuleFeature;
use App\Models\CommandeVente;
use App\Models\Depense;
use App\Models\Organization;
use App\Observers\DepenseObserver;
use App\Observers\VenteObserver;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Pennant\Feature;

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
        // Force les URLs générées (mails, redirections...) à utiliser APP_URL
        // plutôt que le Host de la requête entrante : indispensable avec
        // plusieurs environnements (local, IP réseau, staging, prod...)
        // qui peuvent tous recevoir des requêtes sous des hôtes différents.
        $appUrl = config('app.url');
        URL::forceRootUrl($appUrl);
        if (str_starts_with($appUrl, 'https://')) {
            URL::forceScheme('https');
        }

        Password::defaults(function () {
            return Password::min(8)
                ->mixedCase()   // maj + min
                ->symbols();    // caractère spécial
        });

        // Observers
        CommandeVente::observe(VenteObserver::class);
        Depense::observe(DepenseObserver::class);

        // Feature flags Pennant - modules metier.
        // Scope: Organization. Valeur par defaut pilotee par ModuleFeature::defaultState().
        // Persistance: driver database (table features).
        foreach (ModuleFeature::ALL as $module) {
            Feature::define($module, fn (Organization $org) => ModuleFeature::defaultState($module));
        }
    }
}
