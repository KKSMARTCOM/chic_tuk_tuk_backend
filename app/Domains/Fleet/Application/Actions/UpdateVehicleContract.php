<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\UpdateVehicleContractData;
use App\Models\VehicleContract;
use App\Services\VehicleContractService;

/** Modifier un contrat véhicule — ex-Admin\VehicleContractController::update(). */
final class UpdateVehicleContract
{
    public function __construct(private readonly VehicleContractService $contractService) {}

    public function __invoke(VehicleContract $contract, UpdateVehicleContractData $data): VehicleContract
    {
        return $this->contractService->update($contract, $data->toServicePayload());
    }
}
