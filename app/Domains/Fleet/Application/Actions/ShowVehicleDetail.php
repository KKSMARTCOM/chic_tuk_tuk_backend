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
                'activeVehicleContract.payments',
                'vehicleContracts',
                'activeDriverContract.driver.user',
                'driverContracts.driver.user',
                'pauses',
                'activePause',
            ])
            ->findOrFail($vehicleId);

        return AdminVehicleDetailData::fromModel($vehicle);
    }
}
