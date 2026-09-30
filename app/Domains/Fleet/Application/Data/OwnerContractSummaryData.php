<?php

namespace App\Domains\Fleet\Application\Data;

use App\Domains\Finance\Domain\ContractMonthCalculator;
use App\Models\VehicleContract;
use App\Shared\Data\BaseData;

/**
 * Le contrat tel que la carte compacte du tableau de bord l'affiche.
 *
 * Volontairement plus pauvre qu'OwnerContractDetailData : la liste n'a besoin ni des
 * charges ni des dates, et les calculer pour N véhicules coûterait sans servir.
 */
final class OwnerContractSummaryData extends BaseData
{
    public function __construct(
        public int $contractMonths,
        public int $monthsElapsed,
        public int $monthsRemaining,
        public int $progressPercentage,
        public float $remainingAmount,
    ) {}

    public static function fromModel(VehicleContract $contract): self
    {
        // Mois TRAVAILLÉS (2026-09-30) : un mois sans jour comptabilisé allonge le contrat.
        $worked = ContractMonthCalculator::for($contract)->workedMonths();

        return new self(
            contractMonths: (int) $contract->contract_months,
            monthsElapsed: $worked,
            monthsRemaining: max(0, (int) $contract->contract_months - $worked),
            progressPercentage: $contract->progress_percentage,
            remainingAmount: $contract->remaining_amount,
        );
    }
}
