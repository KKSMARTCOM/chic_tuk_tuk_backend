<?php

// Client Routes

use App\Http\Controllers\Client\OwnerLeaveController;
use App\Http\Controllers\Client\OwnerRedirectController;
use App\Http\Controllers\Client\OwnerPaymentController;
use App\Http\Controllers\Web\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'profil:client'])->prefix('client')->name('client.')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'client'])->name('dashboard')->middleware('permission:view-dashboard');
    /*
     * ⚠️ Deux routes ont été RETIRÉES ici le 2026-09-21 :
     *
     *   client.payments.history → DashboardController::paymentHistory
     *   client.leaves.history   → DashboardController::leaveHistory
     *
     * Ces deux méthodes n'existent pas sur le contrôleur — vérifié par réflexion — donc
     * les routes répondaient 500 à quiconque les atteignait. Aucune vue n'y menait, et
     * aucun nom de route n'était généré ailleurs : elles étaient mortes.
     *
     * Leur retrait n'est pas cosmétique. `client.leaves.history` exigeait `view-leaves`,
     * qui est la permission d'ADMINISTRATION des congés de tous les agents. La conserver
     * aurait poussé à l'ajouter au rôle `client` pour « rendre la route cohérente », et
     * donné à des clients un droit sur les données du personnel.
     */
    // Ajoutez d'autres routes client ici

});

/*
|--------------------------------------------------------------------------
| Espace propriétaire — servi par le front Nuxt depuis la Phase 2
|--------------------------------------------------------------------------
|
| Les chemins Blade sont conservés en REDIRECTION plutôt que supprimés : neuf
| propriétaires ont pu enregistrer un signet, et une 404 ne leur dirait pas où aller.
|
| Les NOMS de routes sont conservés aussi, et ce n'est pas de la prudence de principe :
| `inc/backend/sidebar.blade.php` appelle `route('owner.dashboard')` pour le rôle
| `proprietaire`, et `AuthService` y redirige après une connexion Blade. Les supprimer
| ferait tomber ces deux chemins.
|
| `leaves/{vehicle}` mène à `.../pauses` : l'écran affichait les pauses du véhicule et
| non les congés d'un agent, et l'URL cesse ici de mentir.
|
| ⚠️ Ces renvois sont PUBLICS, et ce n'est pas un oubli. Gardés par `auth:sanctum` +
| `profil:owner`, comme ils l'ont été le temps d'une journée, ils étaient inatteignables
| par la seule personne qu'ils servent : un propriétaire sans session Blade était renvoyé
| vers `/login`, où son profil n'est pas proposé — cul-de-sac. Une adresse de
| réexpédition n'a rien à protéger : elle ne révèle que l'URL que la personne a
| elle-même tapée, et le front applique sa propre authentification à l'arrivée.
|
*/
Route::prefix('owner')->name('owner.')->group(function () {
    Route::get('/dashboard', [OwnerRedirectController::class, 'dashboard'])->name('dashboard');
    Route::get('/vehicles/{vehicle}', [OwnerRedirectController::class, 'vehicle'])->name('vehicles.show');
    Route::get('leaves/{vehicle}', [OwnerRedirectController::class, 'pauses'])->name('leaves.show');
    Route::get('payments/{vehicle}', [OwnerRedirectController::class, 'payments'])->name('payments.show');
});
