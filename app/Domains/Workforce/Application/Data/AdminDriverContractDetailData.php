<?php

namespace App\Domains\Workforce\Application\Data;

use App\Domains\Fleet\Application\Data\AdminVehicleContractMonthData;
use App\Domains\Fleet\Application\Data\AdminVehicleContractPartyData;
use App\Domains\Fleet\Application\Data\VehiclePauseData;
use App\Domains\Fleet\Domain\Enums\VehicleContractStatus;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/**
 * GET /admin/driver-contracts/{id} — ex-`pages.admin.contracts.driver-show`.
 *
 * `totalPaid` et les mois ne comptent que les paiements VALIDÉS, au montant payé par
 * l'agent : le Blade additionnait aussi les paiements annulés ou échoués (2026-09-26).
 * `pauses` sont les pauses VÉHICULE liées au contrat, comme au Blade.
 */
final class AdminDriverContractDetailData extends BaseData
{
    public function __construct(
        public AdminDriverContractListItemData $contract,
        public int $paymentsCount,
        public float $totalPaid,
        /** @var array<int, AdminVehicleContractMonthData> */
        public array $paymentsByMonth,
        /** @var array<int, VehiclePauseData> */
        public array $pauses,
        public ?string $vehicleColor,
        public ?AdminVehicleContractPartyData $owner,
        public ?string $vehicleContractId,
        #[TypeScriptType('?'.VehicleContractStatus::class)]
        public ?string $vehicleContractStatus,
        public ?int $vehicleContractMonths,
        public ?string $vehicleContractNotes,
    ) {}
}
