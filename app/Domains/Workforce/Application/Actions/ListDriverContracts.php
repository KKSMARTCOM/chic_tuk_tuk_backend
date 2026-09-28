<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Domains\Workforce\Application\Data\AdminDriverContractListItemData;
use App\Domains\Workforce\Application\Data\AdminDriverContractPageData;
use App\Models\DriverContract;
use App\Shared\Data\PaginationData;
use App\Shared\Http\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\QueryBuilder;

/** La liste des contrats agents — ex-Admin\DriverContractController::index(). */
final class ListDriverContracts
{
    /**
     * @param  array<string, mixed>  $params  `sort` (created_at, start_date), `page`,
     *                                         `per_page` — paginée depuis le 2026-09-28
     */
    public function __invoke(array $params = []): AdminDriverContractPageData
    {
        $page = ListQuery::paginate(ListQuery::build(self::query(), $params, fn (QueryBuilder $query) => $query
            ->allowedSorts(['created_at', 'start_date'])
            ->defaultSort('-created_at')), $params);

        return new AdminDriverContractPageData(
            contracts: collect($page->items())
                ->map(fn (DriverContract $contract) => AdminDriverContractListItemData::fromModel($contract))
                ->all(),
            pagination: PaginationData::fromPaginator($page),
        );
    }

    /** Ce qu'attend `AdminDriverContractListItemData::fromModel()`. */
    public static function query(): Builder
    {
        return DriverContract::query()
            ->with(['driver.user', 'vehicle'])
            ->withCount(['leaveRequests', 'payments']);
    }
}
