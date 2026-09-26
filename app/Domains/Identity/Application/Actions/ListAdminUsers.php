<?php

namespace App\Domains\Identity\Application\Actions;

use App\Domains\Identity\Application\Data\AdminUserListItemData;
use App\Domains\Identity\Application\Data\AdminUserPageData;
use App\Domains\Identity\Application\Data\AdminUserRoleData;
use App\Domains\Identity\Application\Data\AdminUserStatsData;
use App\Models\Role;
use App\Models\User;
use App\Services\UserService;

/**
 * La liste des administrateurs — ex-Admin\UserController::index(), par la même requête
 * que le Blade (`UserService::getAll()` : `profil=admin`, recherche, statut).
 */
final class ListAdminUsers
{
    public function __construct(
        private readonly UserService $userService,
        private readonly AssignableRoles $assignableRoles,
    ) {}

    /** @param  array{search?: ?string, is_active?: ?string}  $filters */
    public function __invoke(array $filters = []): AdminUserPageData
    {
        $stats = $this->userService->getStats();

        return new AdminUserPageData(
            users: $this->userService->getAll($filters)
                ->map(fn (User $user) => AdminUserListItemData::fromModel($user))
                ->all(),
            stats: new AdminUserStatsData($stats['total'], $stats['active'], $stats['inactive']),
            assignableRoles: ($this->assignableRoles)()
                ->map(fn (Role $role) => AdminUserRoleData::fromModel($role))
                ->all(),
        );
    }
}
