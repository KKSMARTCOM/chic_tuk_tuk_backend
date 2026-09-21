<?php

use App\Domains\Booking\Presentation\Api\V1\Driver\BookingController;
use App\Domains\Booking\Presentation\Api\V1\Driver\DashboardController;
use App\Domains\Workforce\Presentation\Api\V1\Driver\LeaveController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — espace agent, sous-lot 3a : les courses
|--------------------------------------------------------------------------
|
| Même triple garde que l'espace propriétaire :
|
|   - `auth:sanctum` : il faut un jeton valide ;
|   - `abilities:driver` : ce jeton doit avoir été émis POUR un compte agent. C'est plus
|     fort qu'un contrôle de `users.profil` — la restriction voyage avec la crédence ;
|   - `permission:view-bookings` / `edit-bookings` : le droit métier proprement dit.
|
| `token.fresh` passe AVANT l'authentification : le garde de Sanctum écrit `last_used_at`
| à now() pendant qu'il authentifie, et la fenêtre d'inactivité n'expirerait jamais
| personne si on le lisait après lui.
|
| ⚠️ Les routes Blade de l'agent (routes/driver.php) ne portent AUCUNE permission, juste
| `profil:driver`. Les permissions ci-dessous sont donc un resserrement, et l'un des deux
| changements de comportement assumés du sous-lot. Le rôle `driver` de référence porte
| bien view-bookings et edit-bookings.
|
| Le vocabulaire reste `bookings`, jamais « rides » : le modèle, la permission et la base
| parlent déjà de réservations.
|
*/

Route::middleware(['token.fresh', 'auth:sanctum', 'abilities:driver'])
    ->prefix('driver')
    ->name('driver.')
    ->group(function () {
        Route::middleware('permission:view-bookings')->group(function () {
            Route::get('/bookings/available', [BookingController::class, 'available'])
                ->name('bookings.available');
            Route::get('/bookings/assigned', [BookingController::class, 'assigned'])
                ->name('bookings.assigned');
            Route::get('/bookings/history', [BookingController::class, 'history'])
                ->name('bookings.history');
            Route::get('/dashboard', DashboardController::class)
                ->name('dashboard');
        });

        /*
         * Les congés de l'agent.
         *
         * ⚠️ Pas de `permission:` ici, et c'est délibéré. Aucune permission existante ne
         * désigne « ses propres congés » : `view-leaves` et `create-leaves` sont les
         * permissions d'ADMINISTRATION des congés, portées par `lecteur` et `admin`
         * pour gérer ceux de tous les agents. En ajouter une au rôle `driver` créerait
         * une ambiguïté avec celles-là. `abilities:driver` garantit déjà que le jeton a
         * été émis pour un compte agent, et la portée — ses congés à lui — est assurée
         * par `Auth::user()->driver` dans le contrôleur, comme pour les courses.
         */
        Route::get('/leaves', [LeaveController::class, 'index'])->name('leaves.index');
        Route::post('/leaves', [LeaveController::class, 'store'])->name('leaves.store');

        Route::middleware('permission:edit-bookings')->group(function () {
            Route::post('/bookings/{id}/accept', [BookingController::class, 'accept'])
                ->name('bookings.accept');
            Route::post('/bookings/{id}/start', [BookingController::class, 'start'])
                ->name('bookings.start');
            Route::post('/bookings/{id}/complete', [BookingController::class, 'complete'])
                ->name('bookings.complete');
            Route::post('/bookings/{id}/cancel', [BookingController::class, 'cancel'])
                ->name('bookings.cancel');
            Route::post('/bookings/{id}/revoke-subscription', [BookingController::class, 'revoke'])
                ->name('bookings.revoke');
        });
    });
