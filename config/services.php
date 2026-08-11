<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Secret partagé server-to-server pour les routes api/public/* (appelées par
    // l'app vitrine, jamais par un navigateur — voir VerifyVitrineServiceToken).
    // 'url' sert uniquement à rediriger les anciens liens publics (ex: /register/livreur)
    // vers leur nouvel emplacement côté vitrine — le monolithe n'appelle jamais la vitrine.
    'vitrine' => [
        'token' => env('VITRINE_SERVICE_TOKEN'),
        'url' => env('VITRINE_APP_URL', 'https://eau-la-maman.com'),
    ],

];
