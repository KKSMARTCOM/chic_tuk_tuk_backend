<?php

namespace App\Domains\Identity\Application\Actions;

use App\Models\Role;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Retrouve un rôle par son identifiant, ou lève un 404.
 *
 * ⚠️ La clé des rôles est un ENTIER : PostgreSQL rejette la requête entière si on lui
 * compare une chaîne qui n'en est pas un, d'où le contrôle de forme en amont.
 */
final class FindRole
{
    public function __invoke(string $roleId): Role
    {
        if (! ctype_digit($roleId)) {
            throw (new ModelNotFoundException)->setModel(Role::class, [$roleId]);
        }

        return Role::query()->with('permissions')->findOrFail((int) $roleId);
    }
}
