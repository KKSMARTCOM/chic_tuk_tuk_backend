<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Models\Vehicle;

/** Activer ou désactiver un véhicule — ex-Admin\VehicleController::toggleStatus(), posé et non basculé. */
final class SetVehicleStatus
{
    public function __construct(private readonly SaveVehicleAttributes $saveVehicle) {}

    public function __invoke(Vehicle $vehicle, bool $isActive): Vehicle
    {
        return ($this->saveVehicle)($vehicle, ['is_active' => $isActive]);
    }
}
