<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Models\VehicleContract;
use App\Services\VehicleContractService;

/**
 * Supprimer un contrat véhicule — ex-Admin\VehicleContractController::destroy().
 *
 * La règle vit dans `VehicleContractService::delete()`, partagée avec le Blade.
 */
final class DeleteVehicleContract
{
    public function __construct(private readonly VehicleContractService $contractService) {}

    public function __invoke(VehicleContract $contract): void
    {
        $this->contractService->delete($contract);
    }
}
