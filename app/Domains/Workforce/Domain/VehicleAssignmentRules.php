<?php

namespace App\Domains\Workforce\Domain;

use App\Models\DriverContract;
use App\Models\Vehicle;
use App\Shared\Http\ApiException;

/**
 * Un véhicule peut-il être confié à un agent ?
 *
 * Déplacé de `DriverContractService::validateVehicleAssignment()` le 2026-09-27, sans
 * changement : la création, le renouvellement et la modification d'un agent comme la
 * modification d'un contrat agent le vérifient.
 */
final class VehicleAssignmentRules
{
    public static function assertAssignable(Vehicle $vehicle, ?string $excludeDriverId = null, ?string $excludeContractId = null): void
    {
        // ── Règle 1 : véhicule déjà pris par un autre agent ─────
        $vehicleQuery = DriverContract::where('vehicle_id', $vehicle->id)->where('status', 'active');

        if ($excludeDriverId) {
            $vehicleQuery->where('driver_id', '!=', $excludeDriverId);
        }

        // Exclure le contrat qu'on est en train de modifier
        if ($excludeContractId) {
            $vehicleQuery->where('id', '!=', $excludeContractId);
        }

        if ($vehicleQuery->exists()) {
            throw new ApiException(
                409,
                'VEHICLE_ALREADY_ASSIGNED',
                "Le véhicule {$vehicle->vehicle_number} est déjà assigné à un autre agent actif."
            );
        }

        // ── Règle 2 : un agent par véhicule du propriétaire ─────
        $owner = $vehicle->owner;

        if (! $owner) {
            return;
        }

        // Récupérer les véhicules disponibles du propriétaire
        $ownerVehicleIds = $owner->vehicles()->pluck('id');

        $activeAgentsQuery = DriverContract::whereIn('vehicle_id', $ownerVehicleIds)->where('status', 'active');

        if ($excludeDriverId) {
            $activeAgentsQuery->where('driver_id', '!=', $excludeDriverId);
        }

        // Exclure le contrat en cours de modification du comptage
        if ($excludeContractId) {
            $activeAgentsQuery->where('id', '!=', $excludeContractId);
        }

        $activeAgentsCount = $activeAgentsQuery->count();
        $ownerVehiclesCount = $ownerVehicleIds->count();

        if ($activeAgentsCount >= $ownerVehiclesCount) {
            throw new ApiException(
                409,
                'OWNER_HAS_NO_FREE_VEHICLE',
                "Le propriétaire {$owner->name} n'a pas d'autre véhicule disponible. "
                    ."Il possède {$ownerVehiclesCount} véhicule(s) et a déjà {$activeAgentsCount} agent(s) actif(s)."
            );
        }
    }
}
