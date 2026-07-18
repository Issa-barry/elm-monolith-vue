<?php

namespace App\Providers;

use App\Features\ModuleFeature;
use App\Models\CommandeVente;
use App\Models\Depense;
use App\Models\Organization;
use App\Observers\DepenseObserver;
use App\Observers\VenteObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
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

        // Bloque migrate:fresh, migrate:refresh, db:wipe (et apparentés) en
        // production — même avec --force. Contrairement à la simple confirmation
        // interactive par défaut de Laravel (contournable via --force), ceci lève
        // une exception à coup sûr. Ne dépend que de APP_ENV=production sur le
        // serveur, indépendant de qui/quoi lance la commande (SSH manuel, script).
        DB::prohibitDestructiveCommands($this->app->isProduction());

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

        // api/public/* (contact, inscription livreur) : appelées server-to-server
        // par l'app vitrine, donc toutes derrière une seule IP — un plafond par IP
        // sert de garde-fou anti-DoS, pas de limite par utilisateur (les étapes OTP
        // du même flux réutilisent déjà les limiteurs 'otp-send'/'otp-verify' de
        // FortifyServiceProvider, composites téléphone+IP, ceux-là restent corrects
        // même derrière un proxy partagé).
        RateLimiter::for('public-write', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });
    }
}
