<?php

namespace App\Domains\Identity\Domain;

use Database\Seeders\ReferenceRolesAndPermissionsSeeder;

/**
 * Le catalogue de référence vu du domaine. La source de vérité reste le seeder ; cette
 * classe ne fait que l'exposer, pour que le code applicatif ne dépende pas de lui
 * partout.
 */
final class ReferenceCatalog
{
    /** @return list<string> */
    public static function permissionNames(): array
    {
        return ReferenceRolesAndPermissionsSeeder::permissionNames();
    }

    /** @return list<string> */
    public static function roleNames(): array
    {
        return ReferenceRolesAndPermissionsSeeder::roleNames();
    }

    public static function isReferenceRole(string $name): bool
    {
        return in_array($name, self::roleNames(), true);
    }
}
