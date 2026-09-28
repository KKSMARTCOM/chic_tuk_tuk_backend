<?php

namespace App\Domains\Identity\Application\Actions;

use App\Domains\Identity\Application\Data\AdminUserListItemData;
use App\Domains\Identity\Application\Data\AdminUserPageData;
use App\Domains\Identity\Application\Data\AdminUserRoleData;
use App\Domains\Identity\Application\Data\AdminUserStatsData;
use App\Models\Role;
use App\Models\User;
use App\Shared\Data\PaginationData;
use App\Shared\Http\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\QueryBuilder;

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

    /**
     * @param  array<string, mixed>  $params  `filter[search|is_active]`, `sort` (created_at,
     *                                         name, email, is_active), `page`, `per_page`
     */
    public function __invoke(array $params = []): AdminUserPageData
    {
        $stats = $this->getStats();
        $page = ListQuery::paginate($this->query($params), $params);

        return new AdminUserPageData(
            users: collect($page->items())
                ->map(fn (User $user) => AdminUserListItemData::fromModel($user))
                ->all(),
            pagination: PaginationData::fromPaginator($page),
            stats: new AdminUserStatsData($stats['total'], $stats['active'], $stats['inactive']),
            assignableRoles: ($this->assignableRoles)()
                ->map(fn (Role $role) => AdminUserRoleData::fromModel($role))
                ->all(),
        );
    }

    /** Paginée et triée côté serveur depuis le 2026-09-28. */
    private function query(array $params): QueryBuilder
    {
        return ListQuery::build(User::with('roles')->where('profil', 'admin'), $params, fn (QueryBuilder $query) => $query
            ->allowedFilters([
                ListQuery::search(fn (Builder $q, string $search) => $q->where(fn (Builder $inner) => $inner
                    ->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('phone', 'LIKE', "%{$search}%"))),
                ListQuery::boolean('is_active'),
            ])
            ->allowedSorts(['created_at', 'name', 'email', 'is_active'])
            ->defaultSort('-created_at'));
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
