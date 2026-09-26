<?php

namespace App\Domains\Identity\Application\Data;

use App\Domains\Identity\Domain\ReferenceCatalog;
use App\Models\Role;
use App\Shared\Data\BaseData;

/**
 * Une carte de l'onglet « Rôles » — ex-Admin\RoleController::index().
 *
 * `isReference` fige la carte : un rôle de référence ne se modifie ni ne se supprime à
 * l'écran. `usersCount` était en commentaire dans le Blade ; il sert désormais à
 * expliquer pourquoi un rôle porté ne se supprime pas.
 */
final class AdminRoleListItemData extends BaseData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $label,
        public ?string $description,
        public bool $isReference,
        public int $permissionsCount,
        public int $usersCount,
    ) {}

    /** Attend `permissions_count` chargé par `withCount`. */
    public static function fromModel(Role $role, int $usersCount): self
    {
        return new self(
            id: $role->id,
            name: $role->name,
            label: $role->label ?? $role->name,
            description: $role->description,
            isReference: ReferenceCatalog::isReferenceRole($role->name),
            permissionsCount: (int) $role->permissions_count,
            usersCount: $usersCount,
        );
    }
}
