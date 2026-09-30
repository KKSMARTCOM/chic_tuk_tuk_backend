<?php

namespace App\Domains\Fleet\Application\Data;

use App\Domains\Finance\Domain\ContractMonthCalculator;
use App\Models\VehicleContract;
use App\Shared\Data\BaseData;

/**
 * Le solde de pauses d'un contrat véhicule, tel que le propriétaire le lit.
 *
 * Droit : 2 jours par mois de contrat. Les jours pris sont les jours de pause d'AGENT,
 * comptés au calendrier par `ContractMonthCalculator` (2026-09-30). Plusieurs agents
 * pouvant se succéder sur un même contrat, rien n'empêche de dépasser le droit :
 * `pauseOverrun` le dit, et aucun champ n'est jamais négatif.
 */
final class OwnerContractPauseSummaryData extends BaseData
{
    public function __construct(
        public int $pauseAllowance,
        public int $pauseDaysTaken,
        /** Acquis à ce jour (2 par mois travaillé), moins les jours pris. */
        public int $pauseDaysAvailable,
        public int $pauseDaysRemaining,
        public int $pauseOverrun,
    ) {}

    public static function fromContract(VehicleContract $contract, ContractMonthCalculator $calculator): self
    {
        $allowance = 2 * (int) $contract->contract_months;
        $taken = $calculator->pauseDaysTaken();

        return new self(
            pauseAllowance: $allowance,
            pauseDaysTaken: $taken,
            pauseDaysAvailable: max(0, 2 * $calculator->workedMonths() - $taken),
            pauseDaysRemaining: max(0, $allowance - $taken),
            pauseOverrun: max(0, $taken - $allowance),
        );
    }
}
