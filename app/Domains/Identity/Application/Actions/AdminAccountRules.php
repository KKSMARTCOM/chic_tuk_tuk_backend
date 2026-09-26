<?php

namespace App\Domains\Identity\Application\Actions;

use App\Models\Role;
use App\Models\User;
use App\Shared\Http\ApiException;

/**
 * Les règles communes aux écritures sur un compte administrateur.
 *
 * ⚠️ On n'attribue que ce qu'on possède. Sans cette règle, un rôle personnalisé qui
 * reçoit `create-users` pourrait créer un compte `admin` et s'en servir — exactement le
 * défaut que le Blade ouvrait au rôle `utilisateur` en ne gardant ses écritures que par
 * `view-users`. Le même principe protège un compte plus puissant que celui qui agit : on
 * ne désactive, ne modifie ni ne supprime un compte dont on ne possède pas tous les droits.
 */
final class AdminAccountRules
{
    public function ensureRoleWithinActor(User $actor, Role $role): void
    {
        $held = $actor->getAllPermissions()->pluck('name');
        $missing = $role->permissions->pluck('name')->diff($held);

        if ($missing->isNotEmpty()) {
            throw new ApiException(
                403,
                'ROLE_BEYOND_ACTOR',
                "Le rôle « {$role->label} » donne des droits que vous n'avez pas : seul un compte qui les possède tous peut l'attribuer ou agir sur ses titulaires.",
            );
        }
    }

    public function ensureTargetWithinActor(User $actor, User $target): void
    {
        foreach ($target->roles as $role) {
            $this->ensureRoleWithinActor($actor, $role);
        }
    }

    /**
     * Met fin aux sessions ouvertes d'un compte, en effaçant ses jetons.
     *
     * Un compte inactif est de toute façon refusé à chaque requête depuis le 2026-09-26
     * (`AppServiceProvider`). Effacer les jetons garde son sens : après un changement de
     * mot de passe, le compte reste actif et ses anciennes sessions doivent tomber ; et une
     * réactivation ultérieure ne doit pas ranimer des sessions qu'on a voulu couper.
     *
     * Quand on agit sur son propre compte, la session en cours est épargnée.
     */
    public function endSessions(User $target, User $actor): void
    {
        $tokens = $target->tokens();

        $current = $actor->currentAccessToken();
        if ($target->is($actor) && $current !== null && isset($current->id)) {
            $tokens->whereKeyNot($current->id);
        }

        $tokens->delete();
    }
}
