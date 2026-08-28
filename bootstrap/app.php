<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureApiAccountIsActive;
use App\Http\Middleware\EnsureIsStaffAccount;
use App\Http\Middleware\EnsureOrganizationHasSite;
use App\Http\Middleware\EnsurePasswordIsNotExpired;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventCachingOfDynamicResponses;
use App\Http\Middleware\RequireActiveLivreur;
use App\Http\Middleware\RequireModuleEnabled;
use App\Http\Middleware\RequireSiteAssigned;
use App\Http\Middleware\VerifyVitrineServiceToken;
use App\Support\AuthRedirects;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpFoundation\Response;

// Garde de sécurité irrévocable : un run de tests (APP_ENV=testing, positionné par
// phpunit.xml) ne doit JAMAIS pouvoir démarrer contre autre chose que SQLite en
// mémoire — même si bootstrap/cache/config.php est périmé et fait mentir
// config()/app()->environment() (ils lisent le cache, pas l'environnement réel).
// On vérifie donc les variables d'environnement BRUTES, immunisées au cache config,
// avant même que Laravel ne construise le conteneur. Incident du 12/08/2026 : un
// `php artisan test` a tourné contre le MySQL de dev (à cause d'un config cache
// périmé) et RefreshDatabase a vidé toute la base — cf. tests/TestCase.php.
if (getenv('APP_ENV') === 'testing') {
    $driver = getenv('DB_CONNECTION');
    $database = getenv('DB_DATABASE');
    $safe = $driver === 'sqlite' && $database === ':memory:';

    if (! $safe) {
        fwrite(STDERR, sprintf(
            "\n[GARDE SECURITE TESTS] APP_ENV=testing mais DB_CONNECTION=%s DB_DATABASE=%s ".
            "(attendu : sqlite / :memory:). Tests bloqués pour ne pas risquer de toucher une base réelle.\n".
            "Cause probable : config Laravel mise en cache (bootstrap/cache/config.php) qui ignore les\n".
            "surcharges d'environnement de phpunit.xml. Corrigez avec : php artisan config:clear\n\n",
            $driver !== false && $driver !== '' ? $driver : '(vide)',
            $database !== false && $database !== '' ? $database : '(vide)',
        ));
        exit(1);
    }
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            at: (require __DIR__.'/../config/cloudflare.php')['trusted_proxies'],
            headers: Request::HEADER_X_FORWARDED_FOR |
                     Request::HEADER_X_FORWARDED_HOST |
                     Request::HEADER_X_FORWARDED_PORT |
                     Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'module' => RequireModuleEnabled::class,
            'require.site' => RequireSiteAssigned::class,
            'staff' => EnsureIsStaffAccount::class,
            'org.site.required' => EnsureOrganizationHasSite::class,
            'active.livreur' => RequireActiveLivreur::class,
            'account.active' => EnsureAccountIsActive::class,
            'password.not-expired' => EnsurePasswordIsNotExpired::class,
            'vitrine.token' => VerifyVitrineServiceToken::class,
        ]);

        $middleware->redirectUsersTo(function (Request $request): string {
            return AuthRedirects::defaultPathForUser($request->user());
        });

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            PreventCachingOfDynamicResponses::class,
        ]);

        // Filet de sécurité API : coupe l'accès de tout token Sanctum dont le compte a
        // été désactivé depuis son émission (is_active n'était sinon vérifié qu'au
        // login) — voir EnsureApiAccountIsActive. Global pour ne pas dépendre de son
        // ajout manuel sur chaque nouvelle route auth:sanctum.
        $middleware->api(append: [
            EnsureApiAccountIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Forcer JSON sur /api/* quel que soit le header Accept,
        // et respecter Accept: application/json sur les routes web (ex: postJson en tests).
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson()
        );

        // Le message par défaut de Laravel ("Unauthenticated.") est en anglais — contrat
        // JSON cohérent en français sur api/* (login/logout/reset gardent déjà leurs
        // messages dédiés). Ne modifie rien sur les routes web (redirection Fortify
        // habituelle vers /login, gérée en amont par ce type d'exception).
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Non authentifié.'], 401);
            }
        });

        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();
            if (in_array($status, [403, 404, 500]) && ! $request->is('api/*') && ! $request->expectsJson()) {
                return Inertia::render('Errors/Error', ['status' => $status])
                    ->toResponse($request)
                    ->setStatusCode($status);
            }

            return $response;
        });
    })->create();
