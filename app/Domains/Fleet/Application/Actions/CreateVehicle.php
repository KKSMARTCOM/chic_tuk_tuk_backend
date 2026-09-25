<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\SaveVehicleData;
use App\Models\Vehicle;
use App\Services\VehicleService;

/** Créer un véhicule — ex-Admin\VehicleController::store(). */
final class CreateVehicle
{
    public function __construct(private readonly VehicleService $vehicleService) {}

    public function __invoke(SaveVehicleData $data): Vehicle
    {
        return $this->vehicleService->create($data->toServicePayload());
    }
}
