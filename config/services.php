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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openrouteservice' => [
        'key' => env('OPEN_ROUTE_SERVICE_API_KEY'),
    ],

    // La recherche de lieux (2026-10-27) : le serveur public de Komoot, sans clé.
    'photon' => [
        // `PHOTON_URL=` laissé vide dans le .env retombe aussi sur le serveur public.
        'url' => env('PHOTON_URL') ?: 'https://photon.komoot.io/api/',
    ],

    /*
     * Cloudflare Turnstile — protège les endpoints publics (POST /api/v1/public/bookings)
     * contre les robots. Tant que TURNSTILE_SECRET est vide, la vérification est
     * entièrement désactivée : le développement local et les tests ne la subissent pas.
     */
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret' => env('TURNSTILE_SECRET'),
    ],

];
