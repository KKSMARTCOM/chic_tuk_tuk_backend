<?php

use App\Domains\Content\Presentation\Api\V1\Owner\FaqController;
use App\Domains\Finance\Presentation\Api\V1\Owner\DisbursementController;
use App\Domains\Finance\Presentation\Api\V1\Owner\StatementController;
use App\Domains\Fleet\Presentation\Api\V1\Owner\VehicleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — espace propriétaire
|--------------------------------------------------------------------------
|
| Des lectures, consommées par le front `client`. Toutes sont gardées trois fois :
|
|   - `auth:sanctum` : il faut un jeton valide ;
|   - `abilities:owner` : ce jeton doit avoir été émis POUR un compte propriétaire.
|     C'est plus fort qu'un contrôle de `users.profil` — un jeton d'admin est refusé
|     même si on lui attribuait par erreur les permissions `view-own-*` ;
|   - `permission:view-own-*` : le droit métier proprement dit.
|
| `token.fresh` passe avant l'authentification, comme sur le groupe d'auth : le garde
| de Sanctum écrit `last_used_at` à now() pendant qu'il authentifie, et la fenêtre
| d'inactivité n'expirerait jamais personne si on le lisait après lui.
|
| La portée — « seulement MES véhicules » — n'est pas ici mais dans le contrôleur, en
| un seul endroit. Un Route::bind global aurait été plus élégant et faux : le paramètre
| {vehicle} sert aussi aux routes d'administration, qui doivent voir tous les véhicules.
|
*/

Route::middleware(['token.fresh', 'auth:sanctum', 'abilities:owner'])
    ->prefix('owner')
    ->name('owner.')
    ->group(function () {
        Route::get('/vehicles', [VehicleController::class, 'index'])
            ->middleware('permission:view-own-vehicles')
            ->name('vehicles.index');

        Route::get('/vehicles/{id}', [VehicleController::class, 'show'])
            ->middleware('permission:view-own-contracts')
            ->name('vehicles.show');

        Route::get('/vehicles/{id}/preparation-steps/{step}/proof', [VehicleController::class, 'preparationProof'])->whereUuid(['id', 'step'])
            ->middleware('permission:view-own-contracts')
            ->name('vehicles.preparation-proof');

        Route::get('/vehicles/{id}/pauses', [VehicleController::class, 'pauses'])
            ->middleware('permission:view-own-leaves')
            ->name('vehicles.pauses');

        Route::get('/vehicles/{id}/payments', [VehicleController::class, 'payments'])
            ->middleware('permission:view-own-payments')
            ->name('vehicles.payments');

        // Les paiements un par un, à côté du récapitulatif (2026-10-07).
        Route::get('/vehicles/{id}/payment-list', [VehicleController::class, 'paymentList'])
            ->middleware('permission:view-own-payments')
            ->name('vehicles.payment-list');

        // Les fiches de rémunération validées (2026-09-30).
        Route::get('/vehicles/{id}/statements', [StatementController::class, 'index'])
            ->middleware('permission:view-own-payments')
            ->name('vehicles.statements');

        Route::get('/statements/{id}/pdf', [StatementController::class, 'pdf'])
            ->middleware('permission:view-own-payments')
            ->name('statements.pdf');

        // Les versements (2026-10-22) : les trois cadres et la vue Versements de l'onglet Paiements.
        Route::get('/vehicles/{id}/disbursements', [DisbursementController::class, 'index'])
            ->middleware('permission:view-own-payments')
            ->name('vehicles.disbursements');

        Route::get('/disbursements/{id}/proof', [DisbursementController::class, 'proof'])
            ->middleware('permission:view-own-payments')
            ->name('disbursements.proof');

        // La FAQ (2026-10-22) : les questions visibles, écrites par l'administrateur.
        Route::get('/faq', [FaqController::class, 'index'])
            ->middleware('permission:view-own-contracts')
            ->name('faq');
    });
