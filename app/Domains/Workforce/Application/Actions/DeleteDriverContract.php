<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Models\DriverContract;
use App\Services\DriverContractService;

/** Supprimer un contrat agent — la règle vit dans `DriverContractService::delete()`. */
final class DeleteDriverContract
{
    public function __construct(private readonly DriverContractService $contractService) {}

    public function __invoke(DriverContract $contract): void
    {
        $this->contractService->delete($contract);
    }
}
