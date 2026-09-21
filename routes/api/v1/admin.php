<?php

use App\Domains\Booking\Presentation\Api\V1\Admin\DashboardController;
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
    });
