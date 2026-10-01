<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Application\Data\MonthlyPayoutData;
use App\Domains\Finance\Domain\ContractMonthCalculator;
use App\Domains\Finance\Domain\StatementFigures;
use App\Models\RemunerationStatement;
use App\Models\VehicleContract;
use Illuminate\Support\Collection;

/**
 * Le récapitulatif mensuel que lit le propriétaire, fusionné avec ses fiches (spec §6.1).
 *
 * Un mois dont la fiche est validée montre les chiffres FIGÉS de la fiche. Un mois clos
 * sans fiche validée ne montre aucun solde : le propriétaire ne doit pas lire un montant
 * que la relecture peut changer. Le mois en cours montre une estimation, marquée comme telle.
 * Le report du déficit (2026-09-29) a disparu : le compte de charges le remplace.
 */
final class BuildMonthlyPayoutRecap
{
    public function __construct(private readonly BuildStatementFigures $figures) {}

    /** @return Collection<int, MonthlyPayoutData> */
    public function __invoke(VehicleContract $contract): Collection
    {
        $calculator = ContractMonthCalculator::for($contract);
        $firstMonth = (string) config('remuneration.first_month');
        $statements = $contract->remunerationStatements()->where('status', 'validated')->get()
            ->keyBy(fn (RemunerationStatement $s) => $s->monthKey());

        return collect($calculator->monthKeys())
            ->map(function (string $key) use ($calculator, $contract, $firstMonth, $statements) {
                $month = $calculator->month($key);
                $statement = $statements->get($key);

                [$status, $money, $estimate] = match (true) {
                    $statement !== null => ['validated', StatementFigures::fromArray($statement->figures), false],
                    $month->isCurrent => ['current', ($this->figures)($contract, $key), true],
                    $key >= $firstMonth => ['review_pending', null, false],
                    default => ['before_statements', null, false],
                };

                return new MonthlyPayoutData(
                    month: $key,
                    isCurrent: $month->isCurrent,
                    isWorked: $month->isWorked,
                    status: $status,
                    businessDays: $money?->businessDays ?? $month->businessDays,
                    countedDays: $money?->countedDays ?? $month->countedDays,
                    agentLeaveDays: $money?->pauseDays ?? $month->pauseDays,
                    immobilizationDays: $money?->immobilizationDays ?? $month->immobilizationDays,
                    pendingCount: $money?->pendingCount ?? $month->pendingCount,
                    pendingAmount: $money?->pendingAmount ?? $month->pendingAmount,
                    revenue: $money?->revenue ?? ($status === 'before_statements' ? $month->validatedAmount : null),
                    recovered: $money?->recovered,
                    chargesDeducted: $money?->deductedTotal,
                    balanceDue: $money?->balanceDue,
                    isEstimate: $estimate,
                    statementId: $statement?->id,
                    statementNumber: $statement?->number,
                    hasPdf: $statement?->pdf_path !== null,
                    workedMonthsToDate: $calculator->workedMonthsUntil($key),
                    pdfState: $statement?->pdfState(),
                    downloadsLeft: $statement?->ownerDownloadsLeft(),
                    sentByEmail: $statement !== null ? $statement->delivery !== 'none' : null,
                );
            })
            ->sortByDesc('month')
            ->values();
    }
}
