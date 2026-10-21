<?php

namespace App\Domains\Workforce\Domain;

use App\Models\Driver;
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
    /**
     * Un véhicule quitté par son agent (pause `agent_change` en cours) ne repart que par une
     * RECONDUCTION, qui reprend la durée restante (spec 2026-10-21, §3.5). Un nouveau contrat
     * ou un déplacement le remettrait en service sur un contrat repris du début. Les listes
     * l'excluaient déjà ; l'API le refuse depuis le 2026-10-21. Jamais appelée par
     * `CreateRenewalContract`.
     */
    public static function assertNotAwaitingRenewal(Vehicle $vehicle): void
    {
        if ($vehicle->activePause?->reason_type === 'agent_change') {
            throw new ApiException(
                409,
                'VEHICLE_AWAITS_RENEWAL',
                'Ce véhicule attend la reconduction du contrat de son ancien agent.'
            );
        }
    }

    /**
     * Un compte d'agent fermé — par son départ, ou par un administrateur — ne reçoit plus de
     * travail, quel que soit le rôle de qui le lui donne : seul un administrateur le rouvre
     * (spec 2026-10-21, §4.4).
     */
    public static function assertAccountOpen(Driver $driver): void
    {
        if (! $driver->user?->is_active) {
            throw new ApiException(
                409,
                'DRIVER_ACCOUNT_DISABLED',
                'Le compte de cet agent est désactivé : seul un administrateur peut le rouvrir.'
            );
        }
    }

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
