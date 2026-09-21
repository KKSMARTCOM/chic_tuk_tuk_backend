<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Surface consommée par les fronts Nuxt (landing + app). Additive : tant que
| ces routes ne sont pas appelées, elles n'ont aucun effet sur l'application
| Blade servie par routes/web.php.
|
| Les fichiers par espace (auth, admin, driver, owner, client, public) seront
| ajoutés ici au fur et à mesure.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    require __DIR__ . '/api/v1/public.php';
    require __DIR__ . '/api/v1/auth.php';
    require __DIR__ . '/api/v1/owner.php';
    require __DIR__ . '/api/v1/driver.php';
    require __DIR__ . '/api/v1/notifications.php';

    // Sonde applicative : sert à valider la chaîne CORS + déploiement depuis le front.
    Route::get('/health', fn () => response()->json([
        'status'  => 'ok',
        'env'     => config('app.env'),
        'version' => 'v1',
        'time'    => now()->toIso8601String(),
    ]))->name('health');
});
