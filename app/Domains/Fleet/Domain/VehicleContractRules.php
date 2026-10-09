<?php

namespace App\Domains\Fleet\Domain;

use App\Models\Vehicle;
use App\Models\VehicleContract;
use App\Shared\Http\ApiException;

/**
 * Les gardes d'un contrat véhicule, partagées par sa création et sa modification.
 *
 * Déplacées de `VehicleContractService` le 2026-09-27, sans changement.
 */
final class VehicleContractRules
{
    /**
     * Un véhicule ne porte un contrat actif que s'il a un propriétaire et aucun autre
     * contrat actif. Un contrat en attente compte comme actif : un seul contrat vivant par
     * véhicule (2026-10-09).
     */
    public static function assertCanCarryAnActiveContract(Vehicle $vehicle, ?VehicleContract $except = null): void
    {
        VehicleContractRules::assertHasOwner($vehicle);

        $hasAnotherActiveContract = $vehicle->vehicleContracts()
            ->whereIn('status', ['active', 'pending'])
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))
            ->exists();

        if ($hasAnotherActiveContract) {
            throw new ApiException(
                409,
                'VEHICLE_HAS_ACTIVE_CONTRACT',
                "Le véhicule {$vehicle->vehicle_number} a déjà un contrat actif."
            );
        }
    }

    /** Le contrat est au nom du propriétaire du véhicule : sans lui, pas de contrat. */
    public static function assertHasOwner(Vehicle $vehicle): void
    {
        if (! $vehicle->owner_id) {
            throw new ApiException(
                409,
                'VEHICLE_HAS_NO_OWNER',
                "Le véhicule {$vehicle->vehicle_number} n'a pas de propriétaire : rattachez-le d'abord depuis l'écran d'un propriétaire."
            );
        }
    }
}
