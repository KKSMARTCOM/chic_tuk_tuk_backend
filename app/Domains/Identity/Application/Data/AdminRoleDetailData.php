<?php

namespace App\Domains\Identity\Application\Data;

use App\Domains\Identity\Domain\PermissionFamily;
use App\Domains\Identity\Domain\ReferenceCatalog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Shared\Data\BaseData;

/**
 * GET /admin/roles/{role} — la fiche d'un rôle, ex-`roles-permissions.show-role`.
 *
 * Le Blade y affichait les noms TECHNIQUES des permissions ; la fiche porte ici leur
 * libellé et leur famille.
 */
final class AdminRoleDetailData extends BaseData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $label,
        public ?string $description,
        public bool $isReference,
        /** @var array<int, AdminPermissionData> */
        public array $permissions,
        /** @var array<int, AdminRoleUserData> */
        public array $users,
    ) {}

    public static function fromModel(Role $role): self
    {
        return new self(
            id: $role->id,
            name: $role->name,
            label: $role->label ?? $role->name,
            description: $role->description,
            isReference: ReferenceCatalog::isReferenceRole($role->name),
            permissions: $role->permissions
                ->sortBy(fn (Permission $permission) => PermissionFamily::sortKey($permission->name))
                ->map(fn (Permission $permission) => AdminPermissionData::fromModel($permission))
                ->values()->all(),
            users: $role->users()->orderBy('name')->get()
                ->map(fn (User $user) => AdminRoleUserData::fromModel($user))
                ->all(),
        );
    }
}
