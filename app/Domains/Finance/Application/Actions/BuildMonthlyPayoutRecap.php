<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Application\Data\MonthlyPayoutData;
use App\Domains\Finance\Domain\ContractMonthCalculator;
use App\Models\Payment;
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
    public function __construct(
        private readonly BuildStatementFigures $figures,
        private readonly PresentStatementFigures $present,
    ) {}

    /** @return Collection<int, MonthlyPayoutData> */
    public function __invoke(VehicleContract $contract): Collection
    {
        $calculator = ContractMonthCalculator::for($contract);
        $firstMonth = (string) config('remuneration.first_month');
        // Interrupteur éteint (spec 2026-10-02, §3.2) : aucune fiche, et tout mois clos
        // montre ses recettes validées sans solde, comme avant les fiches. Le mois en cours
        // aussi : son estimation dépend des fiches antérieures, que la reconstitution valide
        // une à une — le solde bougerait devant le propriétaire à chaque relecture.
        $visible = RemunerationStatement::visibleToOwners();
        $statements = $visible
            ? $contract->remunerationStatements()->where('status', 'validated')->get()
                ->keyBy(fn (RemunerationStatement $s) => $s->monthKey())
            : collect();

        // Les recouvrés des fiches anciennes, en une requête pour tous les mois (spec 2026-10-22, §4.3).
        $recoveredCounts = $statements->isEmpty() ? collect() : Payment::query()
            ->join('remuneration_statements', 'remuneration_statements.id', '=', 'payments.remuneration_statement_id')
            ->whereIn('payments.remuneration_statement_id', $statements->pluck('id'))
            ->whereColumn('payments.payment_month', '<', 'remuneration_statements.month')
            ->groupBy('payments.remuneration_statement_id')
            ->selectRaw('payments.remuneration_statement_id as statement_id, count(*) as recovered')
            ->pluck('recovered', 'statement_id');

        return collect($calculator->monthKeys())
            ->map(function (string $key) use ($calculator, $contract, $firstMonth, $statements, $visible, $recoveredCounts) {
                $month = $calculator->month($key);
                $statement = $statements->get($key);

                [$status, $money, $estimate] = match (true) {
                    $statement !== null => ['validated', ($this->present)($statement, (int) ($recoveredCounts[$statement->id] ?? 0)), false],
                    $month->isCurrent => ['current', $visible ? ($this->figures)($contract, $key, ownerEstimate: true) : null, true],
                    $visible && $key >= $firstMonth => ['review_pending', null, false],
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
                    revenue: $money?->revenue ?? ($money === null && $status !== 'review_pending' ? $month->validatedAmount : null),
                    recovered: $money?->recovered,
                    // Ancienne présentation : la carte garde ses anciennes lignes, recouvrés en francs.
                    totalRevenue: $money?->legacyLayout ? null : $money?->totalRevenue,
                    recoveredCount: $money?->recoveredCount,
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
