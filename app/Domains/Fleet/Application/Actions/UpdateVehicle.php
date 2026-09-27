<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\SaveVehicleData;
use App\Models\Vehicle;

/** Modifier un véhicule — ex-Admin\VehicleController::update(). */
final class UpdateVehicle
{
    public function __construct(private readonly SaveVehicleAttributes $saveVehicle) {}

    public function __invoke(Vehicle $vehicle, SaveVehicleData $data): Vehicle
    {
        return ($this->saveVehicle)($vehicle, $data->toServicePayload());
    }
}
