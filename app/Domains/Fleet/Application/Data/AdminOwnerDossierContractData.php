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
        /** Début + durée, pour un contrat qui n'a pas encore de date de fin (2026-11-04). */
        public ?string $plannedEndDate,
        public float $totalAmount,
        public float $totalPaid,
        public int $progressPercent,
        /** `null` tant que le contrat n'a pas commencé (en préparation, sans date de début). */
        public ?OwnerContractPauseSummaryData $pauses,
    ) {}

    /** `$totalPaid` : le payé déjà sommé par le dossier, une requête pour tous ses contrats. */
    public static function fromModel(VehicleContract $contract, float $totalPaid): self
    {
        $total = (float) $contract->total_amount;

        return new self(
            id: $contract->id,
            status: $contract->status,
            contractMonths: (int) $contract->contract_months,
            startDate: $contract->start_date?->toDateString(),
            endDate: $contract->end_date?->toDateString(),
            plannedEndDate: $contract->planned_end_date?->toDateString(),
            totalAmount: (float) $contract->total_amount,
            totalPaid: $totalPaid,
            // La règle de `VehicleContract::progress_percentage`, sur le payé reçu.
            progressPercent: $total > 0 ? (int) min(100, round($totalPaid / $total * 100)) : 0,
            pauses: $contract->start_date
                ? OwnerContractPauseSummaryData::fromContract($contract, ContractMonthCalculator::for($contract))
                : null,
        );
    }
}
