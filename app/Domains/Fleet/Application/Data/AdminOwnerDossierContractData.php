<?php

namespace App\Domains\Fleet\Application\Data;

use App\Domains\Finance\Domain\ContractMonthCalculator;
use App\Domains\Fleet\Domain\Enums\VehicleContractStatus;
use App\Models\VehicleContract;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/** Le contrat que montre la carte d'un véhicule dans le dossier propriétaire. */
final class AdminOwnerDossierContractData extends BaseData
{
    public function __construct(
        public string $id,
        #[TypeScriptType(VehicleContractStatus::class)]
        public string $status,
        public int $contractMonths,
        public ?string $startDate,
        public ?string $endDate,
        public float $totalAmount,
        public float $totalPaid,
        public int $progressPercent,
        /** `null` tant que le contrat n'a pas commencé (en préparation, sans date de début). */
        public ?OwnerContractPauseSummaryData $pauses,
    ) {}

    public static function fromModel(VehicleContract $contract): self
    {
        return new self(
            id: $contract->id,
            status: $contract->status,
            contractMonths: (int) $contract->contract_months,
            startDate: $contract->start_date?->toDateString(),
            endDate: $contract->end_date?->toDateString(),
            totalAmount: (float) $contract->total_amount,
            totalPaid: $contract->total_paid,
            progressPercent: $contract->progress_percentage,
            pauses: $contract->start_date
                ? OwnerContractPauseSummaryData::fromContract($contract, ContractMonthCalculator::for($contract))
                : null,
        );
    }
}
