<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Models\Vehicle;
use App\Services\VehicleService;

/** Activer ou désactiver un véhicule — ex-Admin\VehicleController::toggleStatus(), posé et non basculé. */
final class SetVehicleStatus
{
    public function __construct(private readonly VehicleService $vehicleService) {}

    public function __invoke(Vehicle $vehicle, bool $isActive): Vehicle
    {
        return $this->vehicleService->update($vehicle, ['is_active' => $isActive]);
    }
}
