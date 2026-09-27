<?php

namespace App\Domains\Identity\Application\Actions;

use App\Domains\Identity\Application\Data\AdminUserListItemData;
use App\Domains\Identity\Application\Data\AdminUserPageData;
use App\Domains\Identity\Application\Data\AdminUserRoleData;
use App\Domains\Identity\Application\Data\AdminUserStatsData;
use App\Models\Role;
use App\Models\User;

/**
 * La liste des administrateurs — ex-Admin\UserController::index(), par la même requête
 * que le Blade (`profil=admin`, recherche, statut). Les deux requêtes viennent de
 * l'ancien `UserService`, déplacées ici sans changement le 2026-09-27.
 */
final class ListAdminUsers
{
    public function __construct(
        private readonly AssignableRoles $assignableRoles,
    ) {}

    /** @param  array{search?: ?string, is_active?: ?string}  $filters */
    public function __invoke(array $filters = []): AdminUserPageData
    {
        $stats = $this->getStats();

        return new AdminUserPageData(
            users: $this->getAll($filters)
                ->map(fn (User $user) => AdminUserListItemData::fromModel($user))
                ->all(),
            stats: new AdminUserStatsData($stats['total'], $stats['active'], $stats['inactive']),
            assignableRoles: ($this->assignableRoles)()
                ->map(fn (Role $role) => AdminUserRoleData::fromModel($role))
                ->all(),
        );
    }

    private function getAll(array $filters = [])
    {
        $query = User::with('roles')
            ->where('profil', 'admin');

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('phone', 'LIKE', "%{$search}%");
            });
        }

        if (isset($filters['profil']) && $filters['profil'] !== '') {
            $query->where('profil', $filters['profil']);
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        return $query->latest()->get();
    }

    private function getStats(): array
    {
        $users = User::where('profil', 'admin');

        return [
            'total' => $users->count(),
            'active' => (clone $users)->where('is_active', true)->count(),
            'inactive' => (clone $users)->where('is_active', false)->count(),
        ];
    }
}
