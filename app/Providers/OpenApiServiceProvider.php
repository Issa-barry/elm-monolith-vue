<?php

namespace App\Providers;

use App\Http\Controllers\Api\Auth\CheckPhoneController;
use App\Http\Controllers\Api\Auth\EmailVerificationController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutAllController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\MeController;
use App\Http\Controllers\Api\Auth\PasswordReset\LookupController as PasswordLookupController;
use App\Http\Controllers\Api\Auth\PasswordReset\ResetController as PasswordResetController;
use App\Http\Controllers\Api\Auth\PasswordReset\VerifyController as PasswordVerifyController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\Auth\RegisterLookupController;
use App\Http\Controllers\Api\Auth\RegisterOtpController;
use App\Http\Controllers\Api\Client\ActiviteController;
use App\Http\Controllers\Api\Client\CommandesController;
use App\Http\Controllers\Api\Client\DashboardController;
use App\Http\Controllers\Api\Client\DepensesController;
use App\Http\Controllers\Api\Client\GainsController;
use App\Http\Controllers\Api\Client\LivraisonsEnCoursController;
use App\Http\Controllers\Api\Client\ProfileController;
use App\Http\Controllers\Api\Client\PropositionsVehiculeController;
use App\Http\Controllers\Api\Client\UpdateNotificationPreferencesController;
use App\Http\Controllers\Api\Client\UpdateProfileController;
use App\Http\Controllers\Api\Client\VehiculeCommissionsController;
use App\Http\Controllers\Api\Client\VehiculeFraisController;
use App\Http\Controllers\Api\Client\VehiculesController;
use App\Http\Controllers\Api\Mobile\ChangePasswordController;
use App\Http\Controllers\Api\Mobile\ContactController as MobileContactController;
use App\Http\Controllers\Api\Mobile\Logistique\ConfirmerDepartController;
use App\Http\Controllers\Api\Mobile\Logistique\DemarrerChargementController;
use App\Http\Controllers\Api\Mobile\Logistique\LivraisonDetailController;
use App\Http\Controllers\Api\Mobile\Logistique\MesLivraisonsController;
use App\Http\Controllers\Api\Mobile\Logistique\SaisirQuantitesChargeesController;
use App\Http\Controllers\Api\Mobile\NotificationsController;
use App\Http\Controllers\Api\Mobile\PushTokenController;
use App\Http\Controllers\Api\Mobile\ScanCommandeController;
use App\Http\Controllers\Api\Public\ContactController as PublicContactController;
use App\Http\Controllers\Api\Public\LivreurRegistrationController as PublicLivreurRegistrationController;
use App\Http\Controllers\Api\Public\ModuleFlagsController;
use App\Http\Controllers\Api\Public\RegisterLookupController as PublicRegisterLookupController;
use App\Http\Controllers\Api\Public\RegisterOtpController as PublicRegisterOtpController;
use App\Http\Controllers\Api\Search\GlobalSearchController;
use App\Models\User;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Configuration OpenAPI/Swagger (dedoc/scramble) — cf. rapport du 27/08/2026.
 *
 * Deux documents distincts sont générés, chacun avec son propre security
 * scheme, parce que ce ne sont pas les mêmes consommateurs :
 * - `default` (/docs/api) : Nuxt + mobile, Bearer Sanctum — routes `api/*`
 *   sauf `api/v1/backoffice/*` (API mobile staff, hors périmètre du premier
 *   jet de doc, cf. config/scramble.php) et `api/public/*` (ci-dessous).
 * - `public` (/docs/public) : app vitrine server-to-server, clé partagée
 *   `X-Vitrine-Key` (jamais un token utilisateur) — routes `api/public/*`
 *   uniquement.
 *
 * Le mapping contrôleur → tag ci-dessous groupe les endpoints dans l'UI sans
 * toucher aux ~30 contrôleurs eux-mêmes (pas d'attribut `#[Group]` à ajouter
 * partout) — un seul fichier à maintenir quand un nouvel endpoint est ajouté.
 */
class OpenApiServiceProvider extends ServiceProvider
{
    /** @var array<class-string, string> */
    private const TAGS_BY_CONTROLLER = [
        // Authentication
        LoginController::class => 'Authentication',
        LogoutController::class => 'Authentication',
        LogoutAllController::class => 'Authentication',
        MeController::class => 'Authentication',
        EmailVerificationController::class => 'Authentication',
        CheckPhoneController::class => 'Authentication',
        RegisterController::class => 'Authentication',
        RegisterLookupController::class => 'Authentication',
        RegisterOtpController::class => 'Authentication',
        PasswordLookupController::class => 'Authentication',
        PasswordVerifyController::class => 'Authentication',
        PasswordResetController::class => 'Authentication',
        ChangePasswordController::class => 'Authentication',

        // Profile
        ProfileController::class => 'Profile',
        UpdateProfileController::class => 'Profile',
        UpdateNotificationPreferencesController::class => 'Profile',

        // Dashboard
        DashboardController::class => 'Dashboard',
        GainsController::class => 'Dashboard',

        // Vehicles
        VehiculesController::class => 'Vehicles',
        VehiculeCommissionsController::class => 'Vehicles',
        VehiculeFraisController::class => 'Vehicles',

        // Expenses
        DepensesController::class => 'Expenses',

        // Activity
        ActiviteController::class => 'Activity',

        // Orders
        CommandesController::class => 'Orders',

        // Vehicle Proposals
        PropositionsVehiculeController::class => 'Vehicle Proposals',

        // Notifications
        NotificationsController::class => 'Notifications',
        PushTokenController::class => 'Notifications',

        // Logistics
        LivraisonsEnCoursController::class => 'Logistics',
        MesLivraisonsController::class => 'Logistics',
        LivraisonDetailController::class => 'Logistics',
        DemarrerChargementController::class => 'Logistics',
        SaisirQuantitesChargeesController::class => 'Logistics',
        ConfirmerDepartController::class => 'Logistics',
        ScanCommandeController::class => 'Logistics',

        // Divers
        MobileContactController::class => 'Divers',
        GlobalSearchController::class => 'Divers',

        // Public (document "public" séparé, cf. registerPublicApi() ci-dessous)
        PublicContactController::class => 'Vitrine (server-to-server)',
        PublicRegisterLookupController::class => 'Vitrine (server-to-server)',
        PublicRegisterOtpController::class => 'Vitrine (server-to-server)',
        PublicLivreurRegistrationController::class => 'Vitrine (server-to-server)',
        ModuleFlagsController::class => 'Vitrine (server-to-server)',
    ];

    public function boot(): void
    {
        $this->registerDocsAccessGate();
        $this->registerTagResolver();
        $this->registerDefaultApiSecurity();
        $this->registerPublicApi();
    }

    /**
     * Local : toujours ouvert (géré nativement par Scramble). Ailleurs (preprod
     * ET prod partagent APP_ENV=production par choix délibéré du projet — cf.
     * .env.preprod.example, seul SENTRY_ENVIRONMENT distingue les deux — donc
     * une seule politique ici pour les deux) : réservé au staff déjà
     * authentifié en session web, même critère que le reste du backoffice
     * (`User::hasBackofficeAccess()`, cf. chantier multi-rôle du 26/08/2026).
     * Jamais de Basic Auth ni de token dédié.
     */
    private function registerDocsAccessGate(): void
    {
        Gate::define('viewApiDocs', fn (?User $user) => $user !== null && $user->hasBackofficeAccess());
    }

    private function registerTagResolver(): void
    {
        Scramble::resolveTagsUsing(function (RouteInfo $routeInfo): array {
            $class = $routeInfo->className();

            // Route closure (pas de contrôleur) : cas de vehicule.photo, non couvert
            // par TAGS_BY_CONTROLLER faute de classe à mapper.
            if ($class === null && $routeInfo->route->getName() === 'vehicule.photo') {
                return ['Vehicles'];
            }

            return [self::TAGS_BY_CONTROLLER[$class] ?? 'Autres'];
        });
    }

    /**
     * Bearer Sanctum sur le document par défaut (Nuxt/mobile) — un token obtenu
     * via `POST /api/auth/login`, jamais un mécanisme d'auth propre à Swagger
     * (bouton "Authorize" de la doc = contrat API normal, cf. rapport §8).
     *
     * `MiddlewareAuthSecurityStrategy` (au lieu d'un `secure()` global manuel) :
     * détecte par route si `auth:sanctum` est réellement présent et met
     * `security: []` sur les routes qui ne l'ont pas (login, inscription, reset
     * mot de passe, photo véhicule publique...) — sans cette détection, TOUTES
     * les routes afficheraient à tort "nécessite un Bearer", y compris
     * `POST /auth/login` lui-même, qui sert justement à en obtenir un.
     */
    private function registerDefaultApiSecurity(): void
    {
        Scramble::configure()->config(array_merge(config('scramble'), [
            'security_strategy' => [
                MiddlewareAuthSecurityStrategy::class,
                [
                    'middleware' => ['auth:sanctum'],
                    'scheme' => SecurityScheme::http('bearer', 'Sanctum')
                        ->setDescription(
                            'Token Sanctum obtenu via `POST /api/auth/login` (champ `token` de la réponse). '
                            .'Envoyer `Authorization: Bearer <token>` sur toute route protégée. '
                            ."Coller un token de développement ici (bouton Authorize) pour tester les endpoints protégés — jamais un token d'un compte réel en production."
                        ),
                ],
            ],
        ]));
    }

    /**
     * Document séparé pour `api/public/*` (app vitrine, jamais un navigateur ni
     * un utilisateur authentifié) — sécurisé par la clé partagée `X-Vitrine-Key`
     * (`VerifyVitrineServiceToken`), pas par un token Sanctum. Toujours vide dans
     * la doc — la vraie valeur vit uniquement dans `VITRINE_SERVICE_TOKEN` côté
     * serveur, jamais commitée ni affichée ici.
     */
    private function registerPublicApi(): void
    {
        Scramble::registerApi('public', [
            // `info` remplace ENTIÈREMENT celui de config('scramble') (fusion non
            // profonde dans GeneratorConfigCollection::register()) — `version` doit
            // donc être répété ici, sinon silencieusement perdu (retombe sur le
            // défaut interne de Scramble, jamais notre API_VERSION).
            'info' => [
                'version' => env('API_VERSION', '1.0.0'),
                'description' => "Endpoints server-to-server consommés exclusivement par l'app vitrine "
                    .'(eau-la-maman.com), jamais par un navigateur visiteur ni par Nuxt/mobile. '
                    .'Authentification par clé partagée `X-Vitrine-Key`, pas par token utilisateur.',
            ],
            'export_path' => 'docs/openapi/public.json',
        ])
            ->routes(fn (Route $route) => Str::startsWith($route->uri(), 'api/public'))
            ->expose(
                ui: fn (Router $router, $action) => $router->get('docs/public', $action)->name('scramble.docs.public.ui'),
                document: fn (Router $router, $action) => $router->get('docs/public.json', $action)->name('scramble.docs.public.document'),
            )
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(
                    SecurityScheme::apiKey('header', 'X-Vitrine-Key')
                        ->setDescription(
                            'Clé partagée server-to-server (env `VITRINE_SERVICE_TOKEN` côté backend) — jamais un token utilisateur, jamais exposée ici. Voir `App\\Http\\Middleware\\VerifyVitrineServiceToken`.'
                        )
                );
            });
    }
}
