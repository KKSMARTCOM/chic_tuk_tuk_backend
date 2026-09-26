<?php

namespace App\Domains\Identity\Application\Data;

use App\Domains\Identity\Domain\PermissionFamily;
use App\Models\Permission;
use App\Models\Role;
use App\Shared\Data\BaseData;

/**
 * Une permission du catalogue, avec sa famille et — dans l'onglet « Permissions » — les
 * rôles qui la portent. Sur la fiche d'un rôle, `roles` reste vide : on sait déjà lequel.
 */
final class AdminPermissionData extends BaseData
{
    public function __construct(
        public string $name,
        public string $label,
        public ?string $description,
        public string $family,
        /** @var array<int, AdminUserRoleData> */
        public array $roles = [],
    ) {}

    public static function fromModel(Permission $permission, bool $withRoles = false): self
    {
        return new self(
            name: $permission->name,
            label: $permission->label ?? $permission->name,
            description: $permission->description,
            family: PermissionFamily::of($permission->name),
            roles: $withRoles
                ? $permission->roles->map(fn (Role $role) => AdminUserRoleData::fromModel($role))->values()->all()
                : [],
        );
    }
}
