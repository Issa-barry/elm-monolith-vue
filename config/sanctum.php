<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Valeur par défaut du package, inerte tant que
    | Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful n'est
    | ajouté à aucun groupe de middleware (ce n'est pas le cas ici — voir
    | bootstrap/app.php). Ce chantier fiabilise l'auth API Bearer existante,
    | il n'active PAS le mode SPA stateful — cf. docs/api-auth-contract.md,
    | section "Prêt pour SPA directe ?".
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | Avant cette config (non publiée jusqu'ici, donc valeur package par défaut
    | = null = jamais d'expiration), un token Personal Access Token restait
    | valide indéfiniment tant qu'il n'était pas explicitement révoqué (logout,
    | logout-all, reset password) — cf. audit backend du 26/08/2026.
    |
    | 90 jours par défaut : assez long pour ne pas gêner l'usage mobile actuel
    | (pas de refresh token, reconnexion = nouveau login), assez court pour
    | qu'un token oublié/fuité finisse par expirer de lui-même. Ajustable par
    | environnement via SANCTUM_EXPIRATION_MINUTES sans redéploiement de code.
    | Un `sanctum:prune-expired` quotidien nettoie les tokens expirés en base
    | (cf. routes/console.php) — la valeur ci-dessous suffit déjà à les rendre
    | inutilisables avant cette purge.
    |
    */

    'expiration' => env('SANCTUM_EXPIRATION_MINUTES', 60 * 24 * 90),

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
