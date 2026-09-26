<?php

namespace App\Domains\Identity\Application\Actions;

use App\Domains\Identity\Domain\ReferenceCatalog;
use App\Models\Role;
use App\Models\User;
use App\Shared\Http\ApiException;

/** Les règles communes aux écritures sur un rôle. */
final class RoleRules
{
    /**
     * Un rôle de référence ne se modifie ni ne se supprime à l'écran : le seeder, rejoué à
     * chaque déploiement, le remettrait dans son état de référence sans prévenir.
     */
    public function ensureEditable(Role $role): void
    {
        if (ReferenceCatalog::isReferenceRole($role->name)) {
            throw new ApiException(409, 'ROLE_REFERENCE_LOCKED',
                "Le rôle « {$role->label} » est géré par l'application : il ne se modifie pas depuis cet écran.");
        }
    }

    /**
     * On n'accorde que ce qu'on possède — sans quoi gérer les rôles suffirait à se
     * fabriquer un rôle administrateur.
     *
     * @param  list<string>  $permissions
     */
    public function ensureWithinActor(User $actor, array $permissions): void
    {
        $missing = collect($permissions)->diff($actor->getAllPermissions()->pluck('name'));

        if ($missing->isNotEmpty()) {
            throw new ApiException(403, 'ROLE_BEYOND_ACTOR',
                'Vous ne pouvez accorder que des permissions que vous possédez vous-même.');
        }
    }
}
