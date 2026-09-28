<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Domains\Workforce\Application\Data\AdminDriverListItemData;
use App\Domains\Workforce\Application\Data\AdminDriverPageData;
use App\Domains\Workforce\Application\Data\AdminDriverStatsData;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use App\Shared\Data\PaginationData;
use App\Shared\Http\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * La liste générale des agents — ex-Admin\DriverController::index().
 *
 * ⚠️ Ne pas confondre avec `ListDriversForLeaves` : celle-ci ne garde que les agents
 * ayant eu un contrat, pour un dossier de pauses qui n'a de sens qu'avec un contrat de
 * référence. Ici, TOUS les comptes `profil=driver` sont listés, comme le Blade
 * `/admin/drivers` — un agent tout juste créé, sans aucun contrat ni véhicule, y figure.
 *
 * Filtre en plus le très improbable compte `profil=driver` sans ligne `drivers` : un tel
 * compte n'a pas d'identifiant d'agent, or les routes de ce domaine (comme celles des
 * pauses) sont keyées sur `drivers.id`.
 */
final class ListDrivers
{
    /**
     * @param  array<string, mixed>  $params  `filter[search|is_active|is_available]`, `sort`
     *                                         (created_at, name, email, is_active, vehicle_number,
     *                                         total_trips), `page`, `per_page`
     */
    public function __invoke(array $params = []): AdminDriverPageData
    {
        $page = ListQuery::paginate($this->query($params), $params);

        return new AdminDriverPageData(
            drivers: collect($page->items())->map(fn (User $u) => AdminDriverListItemData::fromModel($u))->all(),
            pagination: PaginationData::fromPaginator($page),
            stats: $this->stats(),
        );
    }

    /**
     * Paginée et triée côté serveur depuis le 2026-09-28.
     *
     * La requête porte sur `users` : deux colonnes de l'écran vivent ailleurs, et se
     * trient par sous-requête — le compteur de courses sur `drivers`, et la plaque du
     * véhicule ACTUEL (contrat agent actif), la même que `currentVehicle`.
     */
    private function query(array $params): QueryBuilder
    {
        $drivers = User::query()
            ->where('profil', 'driver')
            ->whereHas('driver')
            ->with('driver.currentVehicle');

        return ListQuery::build($drivers, $params, fn (QueryBuilder $query) => $query
            ->allowedFilters([
                ListQuery::search(fn (Builder $q, string $search) => $q->where(fn (Builder $inner) => $inner
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhereHas('driver', fn ($driverQuery) => $driverQuery->where('license_number', 'like', "%{$search}%"))
                    // ⚠️ Le VÉHICULE ACTUEL, via `currentVehicle` (table `vehicles`) — pas
                    // `drivers.vehicle_number`, une colonne vestigiale que les migrations du
                    // dépôt déclarent mais que la base de staging n'a plus (constaté le
                    // 2026-09-23 : `column "vehicle_number" does not exist`). Cette clause
                    // faisait partie de TOUTE recherche, peu importe le terme tapé — la
                    // recherche d'agent était donc cassée sans exception.
                    ->orWhereHas('driver.currentVehicle', fn ($vehicleQuery) => $vehicleQuery->where('vehicle_number', 'like', "%{$search}%")))),
                ListQuery::boolean('is_active'),
                ListQuery::boolean('is_available', fn (Builder $q, bool $available) => $q
                    ->whereHas('driver', fn ($driverQuery) => $driverQuery->where('is_available', $available))),
            ])
            ->allowedSorts([
                'created_at', 'name', 'email', 'is_active',
                AllowedSort::callback('total_trips', fn (Builder $q, bool $descending) => $q->orderBy(
                    Driver::select('total_trips')->whereColumn('drivers.user_id', 'users.id')->limit(1),
                    $descending ? 'desc' : 'asc',
                )),
                AllowedSort::callback('vehicle_number', fn (Builder $q, bool $descending) => $q->orderBy(
                    Vehicle::select('vehicles.vehicle_number')
                        ->join('driver_contracts', 'driver_contracts.vehicle_id', '=', 'vehicles.id')
                        ->join('drivers', 'drivers.id', '=', 'driver_contracts.driver_id')
                        ->where('driver_contracts.status', 'active')
                        ->whereColumn('drivers.user_id', 'users.id')
                        ->limit(1),
                    $descending ? 'desc' : 'asc',
                )),
            ])
            ->defaultSort('-created_at'));
    }

    private function stats(): AdminDriverStatsData
    {
        $total = User::where('profil', 'driver')->count();
        $active = User::where('profil', 'driver')->where('is_active', true)->count();

        return new AdminDriverStatsData(
            total: $total,
            active: $active,
            inactive: $total - $active,
            // Même requête que `DriverService::getDriverStats()` : TOUTES les lignes
            // `drivers` disponibles, sans repasser par `profil=driver` sur `users`.
            available: Driver::where('is_available', true)->count(),
        );
    }
}
