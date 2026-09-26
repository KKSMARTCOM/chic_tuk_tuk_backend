<?php

namespace App\Domains\Identity\Application\Actions;

use App\Domains\Identity\Application\Data\AdminRoleListItemData;
use App\Domains\Identity\Application\Data\AdminRolePageData;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

/** L'onglet « Rôles » — ex-Admin\RoleController::index(). Rôles de référence en tête. */
final class ListRoles
{
    public function __invoke(): AdminRolePageData
    {
        // ⚠️ Pas de `withCount('users')` : la relation `users()` de Spatie lit le garde
        // sur l'instance, et `withCount` la résout sur une instance vide — d'où une
        // erreur « Class name must be a valid object ». On compte donc à la source.
        $holders = DB::table(config('permission.table_names.model_has_roles'))
            ->selectRaw('role_id, count(*) as total')
            ->groupBy('role_id')
            ->pluck('total', 'role_id');

        $roles = Role::query()->withCount('permissions')->orderBy('id')->get()
            ->map(fn (Role $role) => AdminRoleListItemData::fromModel($role, (int) ($holders[$role->id] ?? 0)))
            ->sortBy(fn (AdminRoleListItemData $role) => $role->isReference ? 0 : 1)
            ->values()
            ->all();

        return new AdminRolePageData($roles);
    }
}
