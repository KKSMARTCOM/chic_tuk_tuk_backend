<?php

use App\Domains\Booking\Presentation\Api\V1\Admin\BookingController;
use App\Domains\Booking\Presentation\Api\V1\Admin\DashboardController;
use App\Domains\Workforce\Presentation\Api\V1\Admin\LeaveController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — espace administration
|--------------------------------------------------------------------------
|
| Même triple garde que les espaces agent et propriétaire :
|
|   - `auth:sanctum` : il faut un jeton valide ;
|   - `abilities:admin` : ce jeton doit avoir été émis POUR un compte administrateur.
|     C'est plus fort qu'un contrôle de `users.profil` — la restriction voyage avec la
|     crédence, et un jeton d'agent ne peut pas servir ici même si le compte changeait
|     de profil ;
|   - `permission:xxx` : le droit métier proprement dit, route par route.
|
| ⚠️ Le profil `admin` recouvre DEUX rôles très différents. `admin` porte 62 permissions,
| `lecteur` — libellé « Utilisateur » en production — en porte 41, dont 27 écritures. Ce
| n'est donc PAS un rôle en lecture seule, malgré son nom. La garde route par route est
| ce qui les distingue : ne jamais s'appuyer sur `profil:admin` seul pour protéger une
| écriture.
|
| ⚠️ La barre latérale Blade, elle, n'est gardée que par `profil === 'admin'` et propose
| donc à un `lecteur` des liens qu'il ne peut pas ouvrir. La navigation du front Nuxt se
| construisant sur les permissions EFFECTIVES, elle lui en montrera moins — c'est voulu :
| un menu qui ne mène jamais à un refus.
|
*/

Route::middleware(['token.fresh', 'auth:sanctum', 'abilities:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/dashboard', DashboardController::class)
            ->middleware('permission:view-dashboard')
            ->name('dashboard');

        /*
         * Les réservations.
         *
         * ⚠️ `assignable-drivers` est déclarée AVANT `bookings/{booking}` : Laravel
         * confronte les routes dans l'ordre, et « bookings/assignable-drivers »
         * satisferait le paramètre `{booking}` si celui-ci venait en premier. On
         * chercherait alors une réservation dont l'identifiant est « assignable-drivers »
         * et l'écran recevrait un 404 sans cause visible.
         */
        Route::middleware('permission:view-bookings')->group(function () {
            Route::get('/bookings', [BookingController::class, 'index'])->name('bookings.index');
            Route::get('/bookings/assignable-drivers', [BookingController::class, 'assignableDrivers'])
                ->name('bookings.assignable-drivers');
            Route::get('/bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show');
        });

        // Le devis sert AUX DEUX formulaires, création et édition : n'importe laquelle
        // des deux permissions suffit à le demander.
        Route::post('/bookings/quote', [BookingController::class, 'quote'])
            ->middleware('permission:create-bookings,edit-bookings')
            ->name('bookings.quote');

        Route::post('/bookings', [BookingController::class, 'store'])
            ->middleware('permission:create-bookings')->name('bookings.store');

        Route::middleware('permission:edit-bookings')->group(function () {
            Route::put('/bookings/{booking}', [BookingController::class, 'update'])
                ->name('bookings.update');
            Route::post('/bookings/{booking}/assign-driver', [BookingController::class, 'assignDriver'])
                ->name('bookings.assign-driver');
            Route::post('/bookings/{booking}/remove-driver', [BookingController::class, 'removeDriver'])
                ->name('bookings.remove-driver');
            Route::post('/bookings/{booking}/status', [BookingController::class, 'changeStatus'])
                ->name('bookings.status');
            // ⚠️ À part de `status` : rouvrir DÉFAIT la commission, le gain de l'agent et
            // son compteur de trajets. Ce n'est pas un changement d'étiquette.
            Route::post('/bookings/{booking}/reopen', [BookingController::class, 'reopen'])
                ->name('bookings.reopen');
        });

        Route::delete('/bookings/{booking}', [BookingController::class, 'destroy'])
            ->middleware('permission:delete-bookings')->name('bookings.destroy');

        /*
         * Les pauses.
         *
         * ⚠️ Les routes prennent l'identifiant de l'AGENT (`drivers.id`), là où le Blade
         * emploie celui de son COMPTE (`users.id`). Les deux se ressemblent — ce sont
         * deux uuid — et se confondent sans rien casser de visible : les réponses
         * portent donc `id` et `user_id` explicitement.
         */
        Route::middleware('permission:view-leaves')->group(function () {
            Route::get('/leaves', [LeaveController::class, 'index'])->name('leaves.index');
            Route::get('/leaves/{driver}', [LeaveController::class, 'show'])->name('leaves.show');
        });

        Route::get('/leave-requests', [LeaveController::class, 'requests'])
            ->middleware('permission:view-leave-requests')
            ->name('leave-requests.index');

        /*
         * Les écritures sur les pauses.
         *
         * ⚠️ Six de ces huit routes n'ont AUCUNE garde de permission côté Blade — seulement
         * `profil:admin`. L'API est donc plus stricte, comme au sous-lot 3a. Le profil ne
         * suffit pas : il recouvre `admin` et `utilisateur`, et c'est la permission qui les
         * distingue.
         *
         * ⚠️ `edit-leaves` a été ajoutée au catalogue le 2026-09-22 : clôturer ou corriger
         * une pause n'est ni la créer ni la supprimer, et emprunter `create-leaves` aurait
         * dit autre chose. Rejouer le seeder de référence après déploiement.
         */
        Route::post('/leave-requests/{leave}/approve', [LeaveController::class, 'approve'])
            ->middleware('permission:approve-leave-requests')->name('leave-requests.approve');
        Route::post('/leave-requests/{leave}/reject', [LeaveController::class, 'reject'])
            ->middleware('permission:reject-leave-requests')->name('leave-requests.reject');

        Route::middleware('permission:create-leaves')->group(function () {
            Route::post('/drivers/{driver}/leaves/ongoing', [LeaveController::class, 'storeOngoing'])
                ->name('leaves.store-ongoing');
            Route::post('/drivers/{driver}/leaves/historical', [LeaveController::class, 'storeHistorical'])
                ->name('leaves.store-historical');
        });

        Route::middleware('permission:edit-leaves')->group(function () {
            Route::patch('/leaves/{leave}/end', [LeaveController::class, 'end'])->name('leaves.end');
            Route::patch('/leaves/{leave}/historical', [LeaveController::class, 'updateHistorical'])
                ->name('leaves.update-historical');
            Route::patch('/leaves/{leave}/ongoing', [LeaveController::class, 'updateOngoing'])
                ->name('leaves.update-ongoing');
        });

        Route::delete('/leaves/{leave}', [LeaveController::class, 'destroy'])
            ->middleware('permission:delete-leaves')->name('leaves.destroy');
    });
