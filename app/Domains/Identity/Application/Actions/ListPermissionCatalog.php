<?php

namespace App\Domains\Identity\Application\Actions;

use App\Domains\Identity\Application\Data\AdminPermissionCatalogData;
use App\Domains\Identity\Application\Data\AdminPermissionData;
use App\Domains\Identity\Application\Data\AdminPermissionGroupData;
use App\Domains\Identity\Domain\PermissionFamily;
use App\Domains\Identity\Domain\ReferenceCatalog;
use App\Models\Permission;

/**
 * Le catalogue des permissions, groupé par famille — l'onglet « Permissions ».
 *
 * Seules les permissions de référence y figurent : les lignes retirées du catalogue
 * (`manage-payments`, `create-permissions`…) restent en base sans aucun rôle, le seeder
 * ne supprimant rien, et les montrer les ferait passer pour attribuables.
 */
final class ListPermissionCatalog
{
    public function __invoke(): AdminPermissionCatalogData
    {
        $groups = Permission::query()
            ->whereIn('name', ReferenceCatalog::permissionNames())
            ->with('roles')
            ->orderBy('name')
            ->get()
            ->map(fn (Permission $permission) => AdminPermissionData::fromModel($permission, withRoles: true))
            ->groupBy(fn (AdminPermissionData $permission) => $permission->family)
            ->sortBy(fn ($permissions, string $family) => PermissionFamily::rank($family))
            ->map(fn ($permissions, string $family) => new AdminPermissionGroupData(
                label: $family,
                permissions: $permissions->sortBy(fn (AdminPermissionData $p) => PermissionFamily::sortKey($p->name))->values()->all(),
            ))
            ->values()
            ->all();

        return new AdminPermissionCatalogData($groups);
    }
}
