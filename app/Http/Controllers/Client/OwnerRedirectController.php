<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

/**
 * Les anciens chemins Blade du propriétaire, devenus des renvois vers le front Nuxt.
 *
 * Un contrôleur plutôt que des fermetures dans le fichier de routes, pour une raison
 * précise : `docker/start.sh` est en `set -e` et exécute `php artisan route:cache` au
 * démarrage. Laravel 11 sait sérialiser une fermeture, donc les deux fonctionnent
 * aujourd'hui — mais le jour où cette sérialisation échouerait, le conteneur ne
 * démarrerait plus du tout. Pour quatre renvois triviaux, l'assurance est gratuite.
 *
 * `config('app.front_app_url')` et non `env()` : la configuration est mise en cache au
 * démarrage, et `env()` y renverrait null.
 */
class OwnerRedirectController extends Controller
{
    /**
     * L'ancienne page de connexion dédiée au propriétaire.
     *
     * C'était le SEUL chemin par lequel un propriétaire pouvait s'authentifier sur le
     * Blade — la page générique ne propose que Client, Agent et Administrateur. Le
     * renvoyer ici est ce qui supprime la double connexion : il s'authentifie sur le
     * front, une fois, et n'ouvre plus de session Blade devenue sans objet.
     */
    public function login(): RedirectResponse
    {
        return $this->toFront('/login');
    }

    public function dashboard(): RedirectResponse
    {
        return $this->toFront('/owner/dashboard');
    }

    public function vehicle(string $vehicle): RedirectResponse
    {
        return $this->toFront("/owner/vehicles/{$vehicle}");
    }

    /**
     * L'ancienne route s'appelait `leaves` et montrait les PAUSES DU VÉHICULE, pas les
     * pauses d'un agent. Le renvoi corrige le nom au passage.
     */
    public function pauses(string $vehicle): RedirectResponse
    {
        return $this->toFront("/owner/vehicles/{$vehicle}/pauses");
    }

    public function payments(string $vehicle): RedirectResponse
    {
        return $this->toFront("/owner/vehicles/{$vehicle}/payments");
    }

    private function toFront(string $path): RedirectResponse
    {
        return redirect()->away(config('app.front_app_url').$path);
    }
}
