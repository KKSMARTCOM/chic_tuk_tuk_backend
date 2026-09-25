<?php

use App\Domains\Booking\Presentation\Api\V1\Admin\BookingController;
use App\Domains\Booking\Presentation\Api\V1\Admin\DashboardController;
use App\Domains\Fleet\Presentation\Api\V1\Admin\OwnerController;
use App\Domains\Fleet\Presentation\Api\V1\Admin\VehicleController;
use App\Domains\Fleet\Presentation\Api\V1\Admin\VehicleContractController;
use App\Domains\Workforce\Presentation\Api\V1\Admin\DriverController;
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
         * Les agents — ex-Admin\DriverController.
         *
         * ⚠️ `{driver}` est l'identifiant de l'AGENT (`drivers.id`), comme pour les
         * pauses — pas celui de son compte (`users.id`) que prend le Blade.
         *
         * ⚠️ Les deux routes `owners-for-*` sont déclarées AVANT `/drivers/{driver}` :
         * sinon Laravel confronterait « owners-for-new-contract » au paramètre
         * `{driver}` en premier, et chercherait un agent dont l'identifiant est
         * « owners-for-new-contract ». Même piège que `bookings/assignable-drivers`.
         *
         * Le sélecteur propriétaire/véhicule sert À LA FOIS la création (create-drivers)
         * et l'édition (edit-drivers) : d'où l'OR sur les deux permissions.
         */
        Route::middleware('permission:create-drivers,edit-drivers')->group(function () {
            Route::get('/drivers/owners-for-new-contract', [DriverController::class, 'ownersForNewContract'])
                ->name('drivers.owners-for-new-contract');
            Route::get('/drivers/owners-for-renewal', [DriverController::class, 'ownersForRenewal'])
                ->name('drivers.owners-for-renewal');
        });

        Route::middleware('permission:view-drivers')->group(function () {
            Route::get('/drivers', [DriverController::class, 'index'])->name('drivers.index');
            Route::get('/drivers/{driver}', [DriverController::class, 'show'])->name('drivers.show');
        });

        Route::post('/drivers', [DriverController::class, 'store'])
            ->middleware('permission:create-drivers')
            ->name('drivers.store');

        Route::middleware('permission:edit-drivers')->group(function () {
            Route::put('/drivers/{driver}', [DriverController::class, 'update'])->name('drivers.update');
            Route::patch('/drivers/{driver}', [DriverController::class, 'update']);
            Route::post('/drivers/{driver}/toggle-availability', [DriverController::class, 'toggleAvailability'])
                ->name('drivers.toggle-availability');
            Route::post('/drivers/{driver}/toggle-status', [DriverController::class, 'toggleStatus'])
                ->name('drivers.toggle-status');
            Route::post('/drivers/{driver}/password', [DriverController::class, 'updatePassword'])
                ->name('drivers.update-password');
        });

        Route::delete('/drivers/{driver}', [DriverController::class, 'destroy'])
            ->middleware('permission:delete-drivers')
            ->name('drivers.destroy');

        /*
         * Les propriétaires — ex-Admin\OwnerController.
         *
         * ⚠️ Le Blade n'exigeait AUCUNE permission sur `/admin/owners` : les quatre
         * `*-owners` ont été ajoutées au catalogue le 2026-09-25 pour ces routes. Rejouer
         * le seeder de référence après déploiement.
         *
         * ⚠️ `available-vehicles` est déclarée AVANT `/owners/{owner}` — même piège que
         * `bookings/assignable-drivers`. Elle sert à la création ET à l'édition.
         */
        Route::get('/owners/available-vehicles', [OwnerController::class, 'availableVehicles'])
            ->middleware('permission:create-owners,edit-owners')
            ->name('owners.available-vehicles');

        Route::middleware('permission:view-owners')->group(function () {
            Route::get('/owners', [OwnerController::class, 'index'])->name('owners.index');
            Route::get('/owners/{owner}', [OwnerController::class, 'show'])->name('owners.show');
        });

        Route::post('/owners', [OwnerController::class, 'store'])
            ->middleware('permission:create-owners')
            ->name('owners.store');

        Route::middleware('permission:edit-owners')->group(function () {
            Route::put('/owners/{owner}', [OwnerController::class, 'update'])->name('owners.update');
            Route::post('/owners/{owner}/toggle-status', [OwnerController::class, 'setStatus'])
                ->name('owners.toggle-status');
            Route::post('/owners/{owner}/password', [OwnerController::class, 'updatePassword'])
                ->name('owners.update-password');
        });

        Route::delete('/owners/{owner}', [OwnerController::class, 'destroy'])
            ->middleware('permission:delete-owners')
            ->name('owners.destroy');

        /*
         * Les véhicules et leurs pauses — ex-Admin\VehicleController.
         *
         * ⚠️ Côté Blade, `Route::resource('vehicles')` n'exigeait que `view-vehicles`,
         * création, modification et suppression comprises. Ici, une permission par écriture.
         */
        Route::middleware('permission:view-vehicles')->group(function () {
            Route::get('/vehicles', [VehicleController::class, 'index'])->name('vehicles.index');
            Route::get('/vehicles/{vehicle}', [VehicleController::class, 'show'])->name('vehicles.show');
        });

        Route::post('/vehicles', [VehicleController::class, 'store'])
            ->middleware('permission:create-vehicles')->name('vehicles.store');

        Route::middleware('permission:edit-vehicles')->group(function () {
            Route::put('/vehicles/{vehicle}', [VehicleController::class, 'update'])->name('vehicles.update');
            Route::post('/vehicles/{vehicle}/toggle-status', [VehicleController::class, 'setStatus'])
                ->name('vehicles.toggle-status');
        });

        Route::delete('/vehicles/{vehicle}', [VehicleController::class, 'destroy'])
            ->middleware('permission:delete-vehicles')->name('vehicles.destroy');

        // ⚠️ Les pauses VÉHICULE, à ne pas confondre avec les pauses AGENT de `/leaves`.
        Route::middleware('permission:manage-vehicle-pauses')->group(function () {
            Route::post('/vehicles/{vehicle}/pauses', [VehicleController::class, 'storePause'])
                ->name('vehicles.pauses.store');
            Route::patch('/vehicle-pauses/{pause}/end', [VehicleController::class, 'endPause'])
                ->name('vehicle-pauses.end');
            Route::delete('/vehicle-pauses/{pause}', [VehicleController::class, 'cancelPause'])
                ->name('vehicle-pauses.cancel');
        });

        // Ce qui préremplit un contrat propriétaire-véhicule. Toute permission qui en fait
        // saisir un l'ouvre : les écrans propriétaires, puis ceux des contrats (F3).
        Route::get('/vehicle-contracts/defaults', [VehicleContractController::class, 'defaults'])
            ->middleware('permission:create-owners,edit-owners,manage-contracts')
            ->name('vehicle-contracts.defaults');

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
