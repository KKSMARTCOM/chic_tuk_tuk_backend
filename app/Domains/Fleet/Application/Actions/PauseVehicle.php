<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\CreateVehiclePauseData;
use App\Models\Vehicle;
use App\Models\VehiclePause;
use App\Services\VehicleService;

/** Mettre un véhicule en pause — ex-Admin\VehicleController::addPause(). */
final class PauseVehicle
{
    public function __construct(private readonly VehicleService $vehicleService) {}

    public function __invoke(Vehicle $vehicle, CreateVehiclePauseData $data): VehiclePause
    {
        return $this->vehicleService->pauseVehicle($vehicle, $data->toServicePayload());
    }
}
