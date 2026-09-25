<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Models\Vehicle;
use App\Services\VehicleService;

/**
 * Supprimer un véhicule — ex-Admin\VehicleController::destroy().
 *
 * La règle vit dans `VehicleService::delete()`, partagée avec le Blade.
 */
final class DeleteVehicle
{
    public function __construct(private readonly VehicleService $vehicleService) {}

    public function __invoke(Vehicle $vehicle): void
    {
        $this->vehicleService->delete($vehicle);
    }
}
