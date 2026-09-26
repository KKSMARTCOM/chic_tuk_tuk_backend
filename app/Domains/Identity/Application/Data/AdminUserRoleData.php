<?php

namespace App\Domains\Identity\Application\Data;

use App\Models\Role;
use App\Shared\Data\BaseData;

/** Un rôle tel que l'écran des administrateurs l'affiche ou le propose. */
final class AdminUserRoleData extends BaseData
{
    public function __construct(
        public string $name,
        public string $label,
    ) {}

    public static function fromModel(Role $role): self
    {
        return new self($role->name, $role->label ?? $role->name);
    }
}
