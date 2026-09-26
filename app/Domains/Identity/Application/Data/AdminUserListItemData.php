<?php

namespace App\Domains\Identity\Application\Data;

use App\Models\Role;
use App\Models\User;
use App\Shared\Data\BaseData;

/** Une ligne de la liste des administrateurs — ex-Admin\UserController::index(). */
final class AdminUserListItemData extends BaseData
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $email,
        public string $phone,
        public ?string $adresse,
        public bool $isActive,
        /** @var array<int, AdminUserRoleData> */
        public array $roles,
        public string $createdAt,
    ) {}

    public static function fromModel(User $user): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            phone: $user->phone,
            adresse: $user->adresse,
            isActive: (bool) $user->is_active,
            roles: $user->roles->map(fn (Role $role) => AdminUserRoleData::fromModel($role))->values()->all(),
            createdAt: $user->created_at->toIso8601String(),
        );
    }
}
