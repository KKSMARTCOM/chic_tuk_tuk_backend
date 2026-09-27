<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\AdminVehicleListItemData;
use App\Domains\Fleet\Application\Data\AdminVehiclePageData;
use App\Domains\Fleet\Application\Data\AdminVehiclePersonData;
use App\Domains\Fleet\Application\Data\AdminVehicleStatsData;
use App\Models\User;
use App\Models\Vehicle;

/** La liste des véhicules — ex-Admin\VehicleController::index(), par sa requête (ex-`VehicleService::getAll()`). */
final class ListVehicles
{
    /** @param  array{search?: ?string, is_active?: ?string, owner_id?: ?string}  $filters */
    public function __invoke(array $filters = []): AdminVehiclePageData
    {
        $vehicles = $this->getAll($filters);

        return new AdminVehiclePageData(
            vehicles: $vehicles->map(fn (Vehicle $vehicle) => AdminVehicleListItemData::fromModel($vehicle))->all(),
            // Les compteurs du Blade, calculés sur la liste affichée.
            stats: new AdminVehicleStatsData(
                total: $vehicles->count(),
                active: $vehicles->where('is_active', true)->count(),
                paused: $vehicles->filter(fn (Vehicle $v) => $v->activePause !== null)->count(),
                withoutContract: $vehicles->filter(fn (Vehicle $v) => $v->activeVehicleContract === null)->count(),
            ),
            owners: User::query()
                ->whereHas('roles', fn ($query) => $query->where('name', 'proprietaire'))
                ->orderBy('name')
                ->get(['id', 'name', 'phone'])
                ->map(fn (User $owner) => new AdminVehiclePersonData($owner->id, $owner->name, $owner->phone))
                ->all(),
        );
    }

    private function getAll(array $filters = [])
    {
        $query = Vehicle::with(['owner', 'activeVehicleContract', 'activeDriverContract.driver.user', 'activePause']);

        if (! empty($filters['search'])) {
            $query->where('vehicle_number', 'LIKE', "%{$filters['search']}%");
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (! empty($filters['owner_id'])) {
            $query->where('owner_id', $filters['owner_id']);
        }

        return $query->latest()->get();
    }
}
