<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\CreateVehicleContractData;
use App\Models\VehicleContract;
use App\Services\VehicleContractService;

/** Créer le contrat d'un véhicule — ex-Admin\VehicleContractController::store(). */
final class CreateVehicleContract
{
    public function __construct(private readonly VehicleContractService $contractService) {}

    public function __invoke(CreateVehicleContractData $data): VehicleContract
    {
        return $this->contractService->create($data->toServicePayload());
    }
}
