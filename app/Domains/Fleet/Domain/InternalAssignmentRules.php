<?php

namespace App\Domains\Fleet\Domain;

use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\VehicleContract;
use App\Shared\Http\ApiException;

/** Les gardes de l'affectation interne (spec 2026-10-09, §3.2). */
final class InternalAssignmentRules
{
    public static function assertContractOpen(VehicleContract $contract): void
    {
        if (! in_array($contract->status, ['active', 'pending'], true)) {
            throw new ApiException(409, 'VEHICLE_CONTRACT_CLOSED', 'Ce contrat propriétaire est terminé ou annulé.');
        }
    }

    /** Ni agent sous contrat, ni affectation interne en cours sur ce véhicule. */
    public static function assertVehicleFree(VehicleContract $contract): void
    {
        $driven = DriverContract::query()->where('vehicle_contract_id', $contract->id)->where('status', 'active')->exists()
            || $contract->activeInternalAssignment()->exists();

        if ($driven) {
            $number = $contract->vehicle?->vehicle_number;
            throw new ApiException(409, 'VEHICLE_ALREADY_DRIVEN', "Le véhicule {$number} a déjà un agent.");
        }
    }

    /** Un agent ne conduit qu'un tricycle à la fois, interne ou sous contrat. */
    public static function assertDriverFree(Driver $driver): void
    {
        $busy = $driver->driverContracts()->where('status', 'active')->exists()
            || $driver->activeInternalAssignment()->exists();

        if ($busy) {
            throw new ApiException(409, 'DRIVER_ALREADY_ASSIGNED', "{$driver->user?->name} a déjà une affectation en cours.");
        }
    }
}
