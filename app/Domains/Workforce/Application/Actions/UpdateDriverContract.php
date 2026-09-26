<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Domains\Workforce\Application\Data\UpdateDriverContractData;
use App\Models\DriverContract;
use App\Models\Vehicle;
use App\Services\DriverContractService;

/** Modifier un contrat agent — ex-Admin\DriverContractController::update(). */
final class UpdateDriverContract
{
    public function __construct(private readonly DriverContractService $contractService) {}

    public function __invoke(DriverContract $contract, UpdateDriverContractData $data): DriverContract
    {
        $vehicle = $data->vehicleId ? Vehicle::findOrFail($data->vehicleId) : $contract->vehicle;

        return $this->contractService->update($contract, [
            'start_date' => $data->startDate,
            'contract_months' => $data->contractMonths,
        ], $vehicle);
    }
}
