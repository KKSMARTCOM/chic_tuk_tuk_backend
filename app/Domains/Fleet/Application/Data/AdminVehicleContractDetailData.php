<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;

/**
 * GET /admin/vehicle-contracts/{id} — ex-`pages.admin.contracts.owner-show`.
 *
 * `currentDriver` est l'agent du contrat agent ACTIF rattaché à ce contrat véhicule,
 * et `currentDriverSince` la date de début de ce contrat agent.
 */
final class AdminVehicleContractDetailData extends BaseData
{
    public function __construct(
        public AdminVehicleContractListItemData $contract,
        public int $paymentsCount,
        /** @var array<int, AdminVehicleContractMonthData> */
        public array $paymentsByMonth,
        /** @var array<int, AdminVehicleContractDriverData> */
        public array $driverContracts,
        /** @var array<int, VehiclePauseData> */
        public array $pauses,
        public ?AdminVehicleContractPartyData $currentDriver,
        public ?string $currentDriverSince,
    ) {}
}
