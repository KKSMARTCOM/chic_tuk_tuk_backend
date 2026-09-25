<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Models\User;

/**
 * Retrouve un propriétaire par son identifiant de compte, ou lève un 404.
 *
 * ⚠️ Le Blade liait `{owner}` à N'IMPORTE QUEL utilisateur : un agent ou un
 * administrateur s'ouvrait dans l'écran d'édition des propriétaires, et
 * `OwnerService::update()` lui imposait `profil=owner` en l'enregistrant. Ici, seul un
 * compte que la liste montre peut être lu ou modifié.
 */
final class FindOwner
{
    public function __invoke(string $ownerId): User
    {
        return User::query()
            ->where('profil', 'owner')
            // `whereHas` et non le scope `role()` de Spatie, qui LÈVE une exception si le
            // rôle n'existe pas en base au lieu de ne rien trouver.
            ->whereHas('roles', fn ($query) => $query->where('name', 'proprietaire'))
            ->findOrFail($ownerId);
    }
}
