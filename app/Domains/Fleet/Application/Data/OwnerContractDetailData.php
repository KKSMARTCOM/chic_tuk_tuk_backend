<?php

namespace App\Domains\Fleet\Application\Data;

use App\Domains\Finance\Application\Data\StatementFiguresData;
use App\Domains\Finance\Domain\ContractMonthCalculator;
use App\Domains\Finance\Domain\StatementFigures;
use App\Models\RemunerationStatement;
use App\Models\VehicleContract;
use App\Models\VehicleContractTerm;
use App\Shared\Data\BaseData;

/**
 * Le contrat véhicule complet, tel que l'onglet Aperçu l'affiche.
 *
 * Les mois effectués et le solde de pauses viennent de `ContractMonthCalculator`, qui
 * compte au calendrier (2026-09-30) : un mois sans jour comptabilisé n'est pas effectué,
 * et allonge d'autant le contrat.
 *
 * ⚠️ La date de fin — prévue comme étendue — n'est plus exposée au propriétaire depuis le
 * 2026-09-30 : il pourrait s'y fier coûte que coûte, alors que la fin réelle dépend des
 * mois non travaillés à venir. Les accesseurs restent sur le modèle pour l'administration.
 */
final class OwnerContractDetailData extends BaseData
{
    public function __construct(
        public int $contractMonths,
        public string $startDate,
        public float $totalAmount,
        public float $totalPaid,
        public float $remainingAmount,
        public float $dailyNetAmount,
        public int $progressPercentage,
        public int $monthsElapsed,
        public int $monthsRemaining,
        public float $totalCharges,
        public float $unlimitedInternet,
        public float $spotifyPremium,
        public float $managerRemuneration,
        /** La convention de la simulation financière : 22 jours ouvrés par mois. */
        public int $totalDays,
        public ?float $investedAmount,
        public OwnerContractPauseSummaryData $pauses,
        /**
         * Les chiffres FIGÉS de la dernière fiche de rémunération validée : les cumuls
         * « réalisés » de l'aperçu. `null` sans fiche validée — le front ne recalcule
         * jamais un cumul.
         */
        public ?StatementFiguresData $latestStatement,
    ) {}

    public static function fromModel(VehicleContract $contract): self
    {
        $calculator = ContractMonthCalculator::for($contract);
        $months = (int) $contract->contract_months;
        $worked = $calculator->workedMonths();
        // Lu dans les réglages, jamais copié sur le contrat : une durée qui n'est plus
        // proposée n'en a pas.
        $invested = VehicleContractTerm::query()->where('months', $months)->value('invested_amount');
        $latest = RemunerationStatement::visibleToOwners()
            ? $contract->remunerationStatements()->where('status', 'validated')->orderByDesc('month')->first()
            : null;

        return new self(
            contractMonths: $months,
            startDate: $contract->start_date->toDateString(),
            totalAmount: (float) $contract->total_amount,
            totalPaid: $contract->total_paid,
            remainingAmount: $contract->remaining_amount,
            dailyNetAmount: $contract->daily_net_amount,
            progressPercentage: $contract->progress_percentage,
            monthsElapsed: $worked,
            monthsRemaining: max(0, $months - $worked),
            totalCharges: $contract->total_charges,
            // `?? 0` : les colonnes ont un défaut à 0 en base, mais les contrats créés
            // avant la migration du 5 août portent des nulls.
            unlimitedInternet: (float) ($contract->unlimited_internet ?? 0),
            spotifyPremium: (float) ($contract->spotify_premium ?? 0),
            managerRemuneration: (float) ($contract->manager_remuneration ?? 0),
            totalDays: $months * 22,
            investedAmount: $invested !== null ? (float) $invested : null,
            pauses: OwnerContractPauseSummaryData::fromContract($contract, $calculator),
            latestStatement: $latest ? StatementFiguresData::fromFigures(StatementFigures::fromArray($latest->figures ?? [])) : null,
        );
    }
}
