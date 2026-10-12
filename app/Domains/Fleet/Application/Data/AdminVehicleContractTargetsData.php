<?php

namespace App\Domains\Fleet\Application\Data;

use App\Domains\Finance\Domain\ContractTargets;
use App\Models\RemunerationStatement;
use App\Models\VehicleContract;
use App\Shared\Data\BaseData;

/**
 * Où en est un contrat propriétaire de ses deux cumuls (spec 2026-10-12, §7.1), selon sa
 * dernière fiche validée : l'écran admin les montre à la place d'une date de fin.
 */
final class AdminVehicleContractTargetsData extends BaseData
{
    public function __construct(
        public float $revenueCumulative,
        public float $revenueTarget,
        public float $chargesCumulative,
        public float $chargesTarget,
        public int $workedMonths,
        public int $contractMonths,
        /** `AAAA-MM`, la dernière fiche validée ; null sans fiche. */
        public ?string $lastStatementMonth,
        public ?string $revenueReachedMonth,
        public ?string $chargesReachedMonth,
    ) {}

    public static function fromContract(VehicleContract $contract): self
    {
        $last = RemunerationStatement::query()
            ->where('vehicle_contract_id', $contract->id)->where('status', 'validated')
            ->orderByDesc('month')->first();
        $figures = $last?->figures ?? [];

        return new self(
            revenueCumulative: (float) ($figures['cumulative_revenue'] ?? 0),
            revenueTarget: ContractTargets::revenue($contract),
            chargesCumulative: (float) ($figures['cumulative_charges'] ?? 0),
            chargesTarget: ContractTargets::charges($contract),
            workedMonths: (int) ($figures['worked_months'] ?? 0),
            contractMonths: (int) $contract->contract_months,
            lastStatementMonth: $last?->monthKey(),
            revenueReachedMonth: $contract->revenue_reached_month?->format('Y-m'),
            chargesReachedMonth: $contract->charges_reached_month?->format('Y-m'),
        );
    }
}
