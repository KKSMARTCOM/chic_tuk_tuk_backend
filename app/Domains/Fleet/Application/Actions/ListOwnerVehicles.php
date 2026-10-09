<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\OwnerVehicleSummaryData;
use App\Models\Vehicle;
use Illuminate\Support\Collection;

/**
 * Les véhicules d'un propriétaire, avec ce que la liste affiche.
 *
 * Reprenait VehicleService::getOwnerVehiclesWithStats, qui renvoyait des objets
 * anonymes destinés à une vue Blade. Cette méthode a été supprimée à la bascule du
 * 2026-09-18 : l'espace propriétaire Blade ne fait plus que rediriger ici.
 */
final class ListOwnerVehicles
{
    /** @return Collection<int, OwnerVehicleSummaryData> */
    public function __invoke(string $ownerId): Collection
    {
        return Vehicle::query()
            ->where('owner_id', $ownerId)
            ->with(['activeVehicleContract', 'liveVehicleContract', 'activePause'])
            ->latest()
            ->get()
            ->map(fn (Vehicle $vehicle) => OwnerVehicleSummaryData::fromModel($vehicle));
    }
}
