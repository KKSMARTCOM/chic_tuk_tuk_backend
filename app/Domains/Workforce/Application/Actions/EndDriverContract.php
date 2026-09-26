<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Domains\Workforce\Application\Data\EndDriverContractData;
use App\Models\DriverContract;
use App\Services\DriverContractService;

/**
 * Terminer un contrat agent — ex-Admin\DriverContractController::end().
 *
 * ⚠️ Effets de bord, dans `DriverContractService::end()` : le véhicule est désactivé,
 * une pause véhicule automatique « changement d'agent » s'ouvre à la date de fin, et le
 * compteur de pauses de l'agent est remis à zéro.
 */
final class EndDriverContract
{
    public function __construct(private readonly DriverContractService $contractService) {}

    public function __invoke(DriverContract $contract, EndDriverContractData $data): DriverContract
    {
        return $this->contractService->end($contract, $data->toServicePayload());
    }
}
