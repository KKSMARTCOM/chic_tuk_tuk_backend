<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Application\Data\AdminCommissionData;
use App\Domains\Finance\Application\Data\AdminCommissionPageData;
use App\Domains\Finance\Application\Data\AdminCommissionStatsData;
use App\Models\Commission;
use App\Shared\Data\PaginationData;
use App\Shared\Http\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/** La liste des commissions — ex-Admin\CommissionController::index(). */
final class ListCommissions
{
    /**
     * @param  array<string, mixed>  $params  `filter[driver_id|search]`, `sort` (amount, date,
     *                                         created_at), `page`, `per_page`
     */
    public function __invoke(array $params = []): AdminCommissionPageData
    {
        $stats = $this->getCommissionStats();
        $page = ListQuery::paginate($this->query($params), $params);

        return new AdminCommissionPageData(
            commissions: collect($page->items())
                ->map(fn (Commission $commission) => AdminCommissionData::fromModel($commission))
                ->all(),
            pagination: PaginationData::fromPaginator($page),
            stats: new AdminCommissionStatsData(
                totalRevenue: (float) $stats['total_revenue'],
                totalCount: (int) $stats['total_count'],
            ),
        );
    }

    /** Paginée côté serveur depuis le 2026-09-28 : une commission naît de chaque course terminée. */
    private function query(array $params): QueryBuilder
    {
        return ListQuery::build(Commission::query()->with(['driver.user', 'booking']), $params, fn (QueryBuilder $query) => $query
            ->allowedFilters([
                AllowedFilter::exact('driver_id'),
                // Groupé (2026-09-26) : sans parenthèses, le `orWhereHas` échappait au filtre
                // d'agent, et un numéro de course d'un autre agent remontait quand même.
                AllowedFilter::callback('search', fn (Builder $q, $search) => $q->where(fn (Builder $inner) => $inner
                    ->whereHas('driver.user', fn ($u) => $u->where('name', 'ilike', '%'.$search.'%'))
                    ->orWhereHas('booking', fn ($b) => $b->where('booking_number', 'ilike', '%'.$search.'%')))),
            ])
            ->allowedSorts(['amount', 'date', 'created_at'])
            ->defaultSort('-created_at'));
    }

    private function getCommissionStats()
    {
        $totalRevenue = Commission::where('status', 'active')->sum('amount');
        $totalCommissionsCount = Commission::count();

        return [
            'total_revenue' => $totalRevenue,
            'total_count' => $totalCommissionsCount,
        ];
    }
}
