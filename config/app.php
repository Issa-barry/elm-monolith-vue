<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Eau-la-maman'),

    /*
    |--------------------------------------------------------------------------
    | Clé de protection de l'assistant d'installation web (/install)
    |--------------------------------------------------------------------------
    |
    | Lue une fois côté serveur (jamais stockée en base, jamais renvoyée au
    | client) — voir InstallWizardController. En on_premise, optionnelle mais
    | recommandée dès la production (sans elle, /install reste ouvert jusqu'à
    | la 1ère installation, qui le referme définitivement via isLocked()). En
    | saas, OBLIGATOIRE : /install y reste accessible indéfiniment, donc sans
    | cette clé l'app refuse tout accès à /install (500) plutôt que de rester
    | ouverte sans protection.
    |
    */

    'install_token' => env('APP_INSTALL_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Mode de déploiement : on_premise (défaut) ou saas
    |--------------------------------------------------------------------------
    |
    | Fixé une fois par déploiement (un .env par client on-premise, un .env pour
    | le SaaS) — jamais déduit du domaine, qui peut changer. Gouverne le verrou
    | de /install (InstallationService::isLocked()) : en on_premise, une seule
    | organisation jamais plus ; en saas, /install reste ouvert indéfiniment
    | pour créer de nouvelles organisations (APP_INSTALL_TOKEN y est alors
    | obligatoire, cf. InstallWizardController).
    |
    */

    'deployment_mode' => env('APP_DEPLOYMENT_MODE', 'on_premise'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'fr'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'fr'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'fr_FR'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
