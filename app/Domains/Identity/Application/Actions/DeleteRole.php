<?php

namespace App\Domains\Identity\Application\Actions;

use App\Models\Role;
use App\Models\User;
use App\Shared\Http\ApiException;

/**
 * Supprimer un rôle créé à l'écran — ex-Admin\RoleController::destroy().
 *
 * ⚠️ Un rôle porté par des comptes ne se supprime pas : ses titulaires perdaient tous
 * leurs droits sans que personne ne l'ait décidé pour eux. Et le Blade ne protégeait
 * que `admin`, `driver` et `client` — `proprietaire` et `utilisateur` se supprimaient
 * par l'URL.
 */
final class DeleteRole
{
    public function __construct(private readonly RoleRules $rules) {}

    public function __invoke(User $actor, Role $role): void
    {
        $this->rules->ensureEditable($role);
        $this->rules->ensureWithinActor($actor, $role->permissions->pluck('name')->all());

        $holders = $role->users()->count();
        if ($holders > 0) {
            throw new ApiException(409, 'ROLE_IN_USE',
                "Impossible de supprimer le rôle « {$role->label} » : {$holders} compte(s) le portent. Attribuez-leur d'abord un autre rôle.");
        }

        $role->delete();
    }
}
