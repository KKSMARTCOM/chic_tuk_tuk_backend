<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Domains\Fleet\Application\Data\AdminAvailableVehicleData;
use App\Models\Vehicle;

/**
 * Les véhicules vers lesquels déplacer un contrat agent : actifs, sous contrat véhicule
 * actif, sans agent.
 *
 * La modale Blade du dossier proposait TOUS les véhicules de tous les propriétaires, et
 * le serveur refusait ensuite ceux déjà conduits — ou acceptait ceux sans contrat
 * véhicule, d'où des paiements sans contrat. La liste ne propose plus que ce qui passe.
 *
 * @return array<int, AdminAvailableVehicleData>
 */
final class ListAssignableVehicles
{
    public function __invoke(): array
    {
        return Vehicle::query()
            ->with('owner')
            ->where('is_active', true)
            ->whereHas('activeVehicleContract')
            ->whereDoesntHave('activeDriverContract')
            ->orderBy('vehicle_number')
            ->get()
            ->map(fn (Vehicle $vehicle) => AdminAvailableVehicleData::fromModel($vehicle))
            ->all();
    }
}
