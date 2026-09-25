<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Models\VehiclePause;
use App\Services\VehicleService;

/** Terminer une pause véhicule — ex-Admin\VehicleController::endPause(). */
final class EndVehiclePause
{
    public function __construct(private readonly VehicleService $vehicleService) {}

    public function __invoke(VehiclePause $pause, string $endDate): VehiclePause
    {
        return $this->vehicleService->endPause($pause, $endDate);
    }
}
