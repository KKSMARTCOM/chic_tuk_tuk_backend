<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\AdminVehicleListItemData;
use App\Domains\Fleet\Application\Data\AdminVehiclePageData;
use App\Domains\Fleet\Application\Data\AdminVehiclePersonData;
use App\Domains\Fleet\Application\Data\AdminVehicleStatsData;
use App\Models\User;
use App\Models\Vehicle;
use App\Shared\Data\PaginationData;
use App\Shared\Http\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\QueryBuilder;

/** La liste des véhicules — ex-Admin\VehicleController::index(), par sa requête (ex-`VehicleService::getAll()`). */
final class ListVehicles
{
    /**
     * @param  array<string, mixed>  $params  `filter[search|is_active|owner_id]`, `sort`
     *                                         (vehicle_number, is_active, created_at), `page`, `per_page`
     */
    public function __invoke(array $params = []): AdminVehiclePageData
    {
        $query = $this->query($params);
        $page = ListQuery::paginate(clone $query, $params);

        return new AdminVehiclePageData(
            vehicles: collect($page->items())->map(fn (Vehicle $vehicle) => AdminVehicleListItemData::fromModel($vehicle))->all(),
            pagination: PaginationData::fromPaginator($page),
            // Les compteurs du Blade, calculés sur la liste FILTRÉE — toute la liste, pas
            // la seule page affichée (2026-09-28). D'où les requêtes sur des clones.
            stats: new AdminVehicleStatsData(
                total: $page->total(),
                active: (clone $query)->where('is_active', true)->count(),
                paused: (clone $query)->whereHas('activePause')->count(),
                withoutContract: (clone $query)->whereDoesntHave('activeVehicleContract')->count(),
            ),
            owners: User::query()
                ->whereHas('roles', fn ($query) => $query->where('name', 'proprietaire'))
                ->orderBy('name')
                ->get(['id', 'name', 'phone'])
                ->map(fn (User $owner) => new AdminVehiclePersonData($owner->id, $owner->name, $owner->phone))
                ->all(),
        );
    }

    /** Paginée et triée côté serveur depuis le 2026-09-28. */
    private function query(array $params): QueryBuilder
    {
        $vehicles = Vehicle::with(['owner', 'activeVehicleContract', 'activeDriverContract.driver.user', 'activePause']);

        return ListQuery::build($vehicles, $params, fn (QueryBuilder $query) => $query
            ->allowedFilters([
                ListQuery::search(fn (Builder $q, string $search) => $q->where('vehicle_number', 'LIKE', "%{$search}%")),
                ListQuery::boolean('is_active'),
                ListQuery::exact('owner_id'),
            ])
            ->allowedSorts(['vehicle_number', 'is_active', 'created_at'])
            ->defaultSort('vehicle_number'));
    }
}
