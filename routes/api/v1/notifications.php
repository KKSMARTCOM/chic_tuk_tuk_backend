<?php

use App\Domains\Notification\Presentation\Api\V1\NotificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — notifications, appareils et préférences
|--------------------------------------------------------------------------
|
| ⚠️ Ces routes ne portent NI `abilities:` NI `permission:`, à la différence de celles
| des espaces agent et propriétaire, et c'est délibéré.
|
| C'est toute l'application qui est installée et notifiée — agent, client, admin et
| propriétaire —, donc un garde `abilities:driver` les réserverait à un seul espace. Et
| aucune permission existante ne désigne « mes propres notifications » : celles du
| catalogue Spatie portent sur l'administration des données d'autrui.
|
| La portée vient de `Auth::user()` dans les actions, exactement comme pour `/auth/me` et
| `/auth/profile`, qui sont gardées de la même façon et pour la même raison.
|
| `token.fresh` passe AVANT `auth:sanctum` : le garde de Sanctum écrit `last_used_at` à
| now() pendant qu'il authentifie, donc la fenêtre d'inactivité n'expirerait jamais
| personne si on la lisait après lui.
|
*/

Route::middleware(['token.fresh', 'auth:sanctum'])
    ->prefix('notifications')
    ->name('notifications.')
    ->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');

        /*
         * ⚠️ L'ORDRE compte. `read-all` et `preferences` sont des chemins littéraux qui
         * doivent être déclarés AVANT tout segment variable susceptible de les capturer.
         * Ici `{id}` est contraint aux entiers — la table `notifications` a une clé
         * auto-incrémentée, seule exception aux uuid du projet — donc il ne les
         * capturerait pas ; la contrainte est explicite pour que cela reste vrai si
         * quelqu'un la retire un jour.
         */
        Route::patch('/read-all', [NotificationController::class, 'markAllRead'])->name('read-all');

        Route::get('/preferences', [NotificationController::class, 'preferences'])->name('preferences');
        Route::patch('/preferences', [NotificationController::class, 'updatePreferences'])->name('preferences.update');

        // Un appareil s'enregistre à la connexion et s'oublie à la déconnexion. Le corps
        // porte le jeton dans les deux cas : le mettre dans l'URL le ferait apparaître
        // dans les journaux d'accès du serveur.
        Route::post('/devices', [NotificationController::class, 'registerDevice'])->name('devices.store');
        Route::delete('/devices', [NotificationController::class, 'forgetDevice'])->name('devices.destroy');

        Route::patch('/{id}/read', [NotificationController::class, 'markRead'])
            ->whereNumber('id')
            ->name('read');
    });
