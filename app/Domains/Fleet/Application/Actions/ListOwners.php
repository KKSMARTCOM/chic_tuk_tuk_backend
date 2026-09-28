<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\AdminOwnerListItemData;
use App\Domains\Fleet\Application\Data\AdminOwnerPageData;
use App\Domains\Fleet\Application\Data\AdminOwnerStatsData;
use App\Models\User;
use App\Shared\Data\PaginationData;
use App\Shared\Http\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * La liste des propriétaires — ex-Admin\OwnerController::index().
 *
 * Même requête que le Blade (ex-`OwnerService`, déplacée ici le 2026-09-27) : un propriétaire est un compte
 * `profil=owner` qui porte AUSSI le rôle `proprietaire`.
 */
final class ListOwners
{
    /**
     * @param  array<string, mixed>  $params  `filter[search|is_active]`, `sort` (created_at,
     *                                         name, email, is_active), `page`, `per_page`
     */
    public function __invoke(array $params = []): AdminOwnerPageData
    {
        $page = ListQuery::paginate($this->query($params), $params);
        $stats = $this->getStats();

        return new AdminOwnerPageData(
            owners: collect($page->items())->map(fn (User $owner) => AdminOwnerListItemData::fromModel($owner))->all(),
            pagination: PaginationData::fromPaginator($page),
            stats: new AdminOwnerStatsData(
                total: $stats['total'],
                active: $stats['active'],
                inactive: $stats['inactive'],
            ),
        );
    }

    /** Paginée et triée côté serveur depuis le 2026-09-28. */
    private function query(array $params): QueryBuilder
    {
        $owners = User::with('roles', 'vehicles')
            ->where('profil', 'owner')
            ->role('proprietaire');

        return ListQuery::build($owners, $params, fn (QueryBuilder $query) => $query
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
        $users = User::where('profil', 'owner')->role('proprietaire');

        return [
            'total' => $users->count(),
            'active' => (clone $users)->where('is_active', true)->count(),
            'inactive' => (clone $users)->where('is_active', false)->count(),
        ];
    }
}
