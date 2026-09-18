<?php

use App\Domains\Identity\Presentation\Api\V1\AuthController;
use App\Domains\Identity\Presentation\Api\V1\PasswordController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — authentification
|--------------------------------------------------------------------------
|
| Jeton Bearer Sanctum, sans session ni cookie posé par l'API. Le throttle par IP
| reste volontairement large : les opérateurs mobiles béninois partagent une IP
| entre de nombreux abonnés (CGNAT), donc une limite serrée punirait des
| utilisateurs innocents sans gêner un attaquant distribué. La vraie défense est
| le verrou de compte (cf. config/identity.php).
|
| Ce raisonnement vaut pour TOUTES les routes de ce fichier, mot de passe compris.
| Les deux routes de mot de passe ont longtemps été à 10 requêtes par heure, trente
| fois plus serré que la connexion, ce qui contredisait l'argument ci-dessus sans
| le dire. Le 2026-09-17 le défaut est devenu concret : deux personnes derrière la
| même IP — un développeur qui éprouvait l'API et un utilisateur qui suivait un
| vrai lien de réinitialisation — ont suffi à produire un 429 au premier essai
| légitime. Aligné sur la connexion depuis.
|
*/

Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:auth-login')
        ->name('login');

    Route::post('/password/forgot', [PasswordController::class, 'forgot'])
        ->middleware('throttle:auth-password')
        ->name('password.forgot');

    Route::post('/password/reset', [PasswordController::class, 'reset'])
        ->middleware('throttle:auth-password')
        ->name('password.reset');

    // token.fresh applique la fenêtre d'inactivité glissante. Il n'est posé QUE sur
    // ce groupe : le réglage global de Sanctum aurait touché les jetons du Blade.
    // Il passe AVANT auth:sanctum, car le garde écrit last_used_at à now() pendant
    // l'authentification : lu après, il vaudrait toujours « à l'instant ».
    Route::middleware(['token.fresh', 'auth:sanctum'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        // Le profil se MODIFIE ici et se LIT par /me : un endpoint de lecture de plus
        // renverrait le même utilisateur sous un autre nom.
        Route::patch('/profile', [AuthController::class, 'updateProfile'])->name('profile.update');
        Route::post('/password', [PasswordController::class, 'change'])->name('password.change');
    });
});
