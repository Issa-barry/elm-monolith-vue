<?php

namespace App\Providers;

use App\Contracts\SmsGateway;
use App\Features\ModuleFeature;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\Depense;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Proprietaire;
use App\Observers\BusinessProfileRoleObserver;
use App\Observers\DepenseObserver;
use App\Observers\VenteObserver;
use App\Services\Sms\NimbaSmsGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
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
        // Fournisseur SMS pour le canal OTP `sms` (App\Services\Otp\Channels\SmsOtpChannel)
        // — cf. rapport du 27/08/2026 (canal vs fournisseur) et audit du
        // 31/08/2026 (intégration Nimba SMS). Changer de fournisseur SMS =
        // changer cette seule ligne, jamais SmsOtpChannel/OtpService/les
        // contrôleurs.
        $this->app->bind(SmsGateway::class, NimbaSmsGateway::class);
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

        // Pendant de `buildDirectory` dans vite.config.ts : `npm run e2e:build`
        // écrit dans public/build-e2e (au lieu de public/build) pour ne pas écraser
        // les assets du serveur de dev avec des helpers Wayfinder pointant vers
        // l'URL e2e (127.0.0.1:8080) — cf. [[project_env_test_isolation]].
        if ($this->app->environment('e2e')) {
            Vite::useBuildDirectory('build-e2e');
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

        // Garantit que Client/Proprietaire/Livreur.user_id et le rôle Spatie
        // correspondant ne divergent jamais, quel que soit le code qui pose ce
        // rattachement (cf. docblock de BusinessProfileRoleObserver).
        Client::observe(BusinessProfileRoleObserver::class);
        Proprietaire::observe(BusinessProfileRoleObserver::class);
        Livreur::observe(BusinessProfileRoleObserver::class);

        // Morph map des relations polymorphiques — alias stables en base plutôt que
        // des noms de classe complets (indépendant des renommages de namespace).
        // À compléter (jamais retirer une entrée existante) quand une nouvelle
        // entité "identifiable" est ajoutée (ex: pieces_identite sur Client, Employe...).
        // Non-enforcing (morphMap, pas enforceMorphMap) : plusieurs relations
        // polymorphes existantes (User via Spatie Permission model_has_roles,
        // AuditLog::auditable, MouvementStock::source...) stockent encore le nom
        // de classe complet et ne sont pas dans cette map — les forcer casserait
        // ces relations non liées à cette fonctionnalité.
        Relation::morphMap([
            'proprietaire' => Proprietaire::class,
            'personne' => Personne::class,
        ]);

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
