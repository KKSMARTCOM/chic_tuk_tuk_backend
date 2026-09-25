<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Models\VehiclePause;
use App\Services\VehicleService;

/**
 * Annuler une pause véhicule posée par erreur — ex-Admin\VehicleController::destroyPause().
 *
 * Seule une pause MANUELLE s'annule ici : voir `VehicleService::cancelManualPause()`.
 */
final class CancelVehiclePause
{
    public function __construct(private readonly VehicleService $vehicleService) {}

    public function __invoke(VehiclePause $pause): void
    {
        $this->vehicleService->cancelManualPause($pause);
    }
}
