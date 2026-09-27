<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Models\VehiclePause;

/** Terminer une pause véhicule — ex-Admin\VehicleController::endPause(). */
final class EndVehiclePause
{
    public function __construct(private readonly ClosePause $closePause) {}

    public function __invoke(VehiclePause $pause, string $endDate): VehiclePause
    {
        return ($this->closePause)($pause, $endDate);
    }
}
