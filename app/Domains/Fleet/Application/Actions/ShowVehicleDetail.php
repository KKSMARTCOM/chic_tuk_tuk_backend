<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\AdminVehicleDetailData;
use App\Models\Vehicle;

/** La fiche d'un véhicule — ex-Admin\VehicleController::show(). */
final class ShowVehicleDetail
{
    public function __invoke(string $vehicleId): AdminVehicleDetailData
    {
        $vehicle = Vehicle::query()
            ->with([
                'owner',
                'liveVehicleContract.payments',
                'liveVehicleContract.activeInternalAssignment.driver.user',
                'liveVehicleContract.activeInternalAssignment.vehicleContract.vehicle',
                'vehicleContracts',
                'activeDriverContract.driver.user',
                'driverContracts.driver.user',
                'driverContracts.leaveRequests',
                'pauses',
                'activePause',
            ])
            ->findOrFail($vehicleId);

        return AdminVehicleDetailData::fromModel($vehicle);
    }
}
