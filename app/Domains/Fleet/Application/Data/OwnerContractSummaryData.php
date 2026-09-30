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
        /** Les paiements validés du contrat. */
        public float $paidAmount,
        /** Les paiements encore en attente de validation. */
        public float $pendingAmount,
        /** Les charges prélevées par les fiches de rémunération validées. */
        public float $chargesDeducted,
        /** Payé sur le revenu cible, en pourcentage, plafonné à 100. */
        public int $revenueProgress,
        public OwnerContractPauseSummaryData $pauses,
    ) {}

    public static function fromModel(VehicleContract $contract): self
    {
        // Un seul calculateur : il charge une fois pauses et paiements, pour les mois
        // travaillés comme pour le solde de pauses.
        $calculator = ContractMonthCalculator::for($contract);
        // Mois TRAVAILLÉS (2026-09-30) : un mois sans jour comptabilisé allonge le contrat.
        $worked = $calculator->workedMonths();

        $paid = (float) $contract->payments()->where('status', 'completed')->sum('net_amount');
        $pending = (float) $contract->payments()->where('status', 'pending')->sum('net_amount');
        $deducted = (float) $contract->remunerationStatements()->where('status', 'validated')->get()
            ->sum(fn ($s) => (float) ($s->figures['deducted_total'] ?? 0));

        return new self(
            contractMonths: (int) $contract->contract_months,
            monthsElapsed: $worked,
            monthsRemaining: max(0, (int) $contract->contract_months - $worked),
            progressPercentage: $contract->progress_percentage,
            remainingAmount: $contract->remaining_amount,
            paidAmount: $paid,
            pendingAmount: $pending,
            chargesDeducted: $deducted,
            revenueProgress: $contract->total_amount > 0 ? (int) min(100, round(100 * $paid / (float) $contract->total_amount)) : 0,
            pauses: OwnerContractPauseSummaryData::fromContract($contract, $calculator),
        );
    }
}
