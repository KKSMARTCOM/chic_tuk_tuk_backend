<?php

/*
|--------------------------------------------------------------------------
| Identité et authentification de l'API
|--------------------------------------------------------------------------
|
| Fichier dédié plutôt qu'un ajout à config/auth.php, dont les clés ont un sens
| imposé par le framework.
|
| ⚠️ Ces réglages ne concernent QUE l'API v1. Le chemin Blade conserve son cookie
| de 30 jours et ses jetons sans expiration : config/sanctum.php reste à
| 'expiration' => null, sans quoi les sessions Blade en cours changeraient de
| comportement.
|
*/

return [
    'token' => [
        // Nom des jetons émis par l'API. Il fut choisi différent du profil parce que la
        // connexion Blade, retirée le 2026-09-27, supprimait les jetons nommés d'après
        // le profil ; le nom reste, les jetons en base le portent.
        'name' => 'api',

        // Fenêtre d'inactivité glissante, vérifiée par EnforceTokenFreshness.
        'inactivity_days' => 14,

        // Plafond absolu, posé sur expires_at à la création et honoré par Sanctum.
        'absolute_days' => 90,
    ],

    // Verrou de compte : la défense principale contre le bourrinage, le throttle
    // par IP restant volontairement large (partage d'IP des opérateurs mobiles).
    'lock' => [
        'max_attempts' => 5,
        'minutes' => 5,
    ],

    'password_reset' => [
        'expire_minutes' => 60,
    ],
];
