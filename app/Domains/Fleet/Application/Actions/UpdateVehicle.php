<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\SaveVehicleData;
use App\Models\Vehicle;
use App\Services\VehicleService;

/** Modifier un véhicule — ex-Admin\VehicleController::update(). */
final class UpdateVehicle
{
    public function __construct(private readonly VehicleService $vehicleService) {}

    public function __invoke(Vehicle $vehicle, SaveVehicleData $data): Vehicle
    {
        return $this->vehicleService->update($vehicle, $data->toServicePayload());
    }
}
