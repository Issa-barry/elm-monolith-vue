<?php

namespace App\Providers;

use App\Features\ModuleFeature;
use App\Models\Organization;
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
        Password::defaults(function () {
            return Password::min(8)
                ->mixedCase()   // maj + min
                ->symbols();    // caractère spécial
        });

        // Feature flags Pennant - modules metier.
        // Scope: Organization. Valeur par defaut pilotee par ModuleFeature::defaultState().
        // Persistance: driver database (table features).
        foreach (ModuleFeature::ALL as $module) {
            Feature::define($module, fn (Organization $org) => ModuleFeature::defaultState($module));
        }
    }
}
