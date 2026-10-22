<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Domain\ChargeLedger;
use App\Domains\Finance\Domain\ContractTargets;
use App\Domains\Finance\Domain\ContractMonthCalculator;
use App\Domains\Finance\Domain\ContractMonthFigures;
use App\Domains\Finance\Domain\StatementFigures;
use App\Models\Payment;
use App\Models\RemunerationStatement;
use App\Models\VehicleContract;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Les chiffres d'une fiche de rémunération (spec 2026-09-30, §4).
 *
 * - recettes : les paiements validés du mois qu'aucune fiche n'a rattachés, encaissés au
 *   plus tard à la date d'établissement (aujourd'hui pour un brouillon sans date) ;
 * - en instance : ceux du mois encore en attente, ou encaissés après cette date ;
 * - recouvré : ceux des mois ANTÉRIEURS dont la fiche est validée, encaissés au plus tard à
 *   la date d'établissement, à partir du premier mois de mise en service seulement.
 *   C'est la DATE qui décide, pas l'ordre des validations (spec 2026-10-01, §4.3) ;
 * - charges : par ligne, reste à recouvrer = ouverture + (dû − prélevé) des fiches validées
 *   antérieures + dû de ce mois, zéro si le mois n'est pas travaillé ;
 * - cumuls : l'historique d'avant la mise en service, puis les fiches validées, puis celle-ci.
 *   Avant la mise en service, les charges des mois travaillés sont réputées prélevées,
 *   moins le reste d'ouverture saisi sur la première fiche.
 * - revenu total et charges (spec 2026-10-12) : les charges dues sont plafonnées au total de
 *   chaque ligne ; les recettes sont coupées au revenu total, et la part au-delà règle les
 *   charges restantes puis revient à l'agence.
 */
final class BuildStatementFigures
{
    public function __invoke(VehicleContract $contract, string $monthKey, ?RemunerationStatement $statement = null, bool $ownerEstimate = false): StatementFigures
    {
        $contract->loadMissing('vehicle.owner');
        $calculator = ContractMonthCalculator::for($contract);
        $month = $calculator->month($monthKey);
        $firstMonth = (string) config('remuneration.first_month');

        $unattached = Payment::query()
            ->where('vehicle_contract_id', $contract->id)
            ->where('status', 'completed')
            ->whereNull('remuneration_statement_id')
            // Comme `ContractMonthCalculator` : un paiement sans mois n'appartient à aucun.
            ->whereNotNull('payment_month')
            // Après la fin du contrat, un paiement revient à l'agence (spec 2026-10-22, §3.1).
            ->when($contract->end_date, fn ($q) => $q->where('payment_date', '<=', $contract->end_date->toDateString()))
            ->get();
        // La date d'établissement fait foi (spec 2026-10-01, §4.3) : aujourd'hui pour un
        // brouillon qui n'en a pas. Un paiement validé sans date d'encaissement — hérité, ou
        // d'avant la reprise — compte comme encaissé.
        $issuedOn = ($statement?->issued_on ?? Carbon::today())->copy()->startOfDay();
        $collected = fn ($p) => $p->collected_on === null || $p->collected_on->copy()->startOfDay()->lte($issuedOn);
        $prior = RemunerationStatement::query()
            ->where('vehicle_contract_id', $contract->id)
            ->where('status', 'validated')
            ->where('month', '<', $monthKey.'-01')
            ->when($statement?->exists, fn ($q) => $q->whereKeyNot($statement->id))
            ->get();
        $validatedMonths = $prior->map(fn (RemunerationStatement $s) => $s->monthKey())->all();

        $revenuePayments = $unattached->filter(fn ($p) => $p->payment_month->format('Y-m') === $monthKey && $collected($p));
        // Recouvré = validé APRÈS la fiche de son mois. Un mois encore sans fiche validée garde
        // ses paiements pour lui : les compter ici les montrerait deux fois — dans l'estimation
        // du mois suivant, puis dans la fiche de leur mois.
        $recoveredPayments = $unattached->filter(fn ($p) => $p->payment_month->format('Y-m') < $monthKey
            && $p->payment_month->format('Y-m') >= $firstMonth
            && in_array($p->payment_month->format('Y-m'), $validatedMonths, true)
            && $collected($p));
        // En instance : en attente, OU validés mais encaissés après la date de la fiche — et
        // seulement sur un jour du calendrier : un jour d'arrêt n'a pas de jour à retirer (§3.1).
        $late = $unattached->filter(fn ($p) => $p->payment_month->format('Y-m') === $monthKey && ! $collected($p)
            && in_array($p->payment_date->toDateString(), $month->countedDates, true));
        $revenue = (float) $revenuePayments->sum('net_amount');
        $recovered = (float) $recoveredPayments->sum('net_amount');

        // Un jour comptabilisé est un jour payé (spec 2026-10-22, §3).
        $dailyAmount = (float) $contract->daily_net_amount;
        $pendingCount = $month->pendingOnCountedDays + $late->count();
        $countedDays = max(0, $month->countedDays - $pendingCount + $recoveredPayments->count());
        if ($ownerEstimate) {
            // L'estimation du propriétaire n'annonce jamais d'argent non reçu : un jour sans
            // paiement (celui du jour naît à 23:30) ne compte pas encore (§4.3).
            $countedDays = max(0, $countedDays - $month->countedDaysWithoutPayment);
        }
        $totalRevenue = round($countedDays * $dailyAmount, 2);
        $receivedAmount = $revenue + $recovered;
        $gap = round($totalRevenue - $receivedAmount, 2);

        $first = $this->firstStatement($contract, $statement);

        $lineAmounts = [
            'internet' => (float) ($contract->unlimited_internet ?? 0),
            'spotify' => (float) ($contract->spotify_premium ?? 0),
            'manager' => (float) ($contract->manager_remuneration ?? 0),
        ];
        $lineTargets = ContractTargets::chargeLines($contract);

        $historyRevenue = (float) Payment::query()
            ->where('vehicle_contract_id', $contract->id)->where('status', 'completed')
            ->where('payment_month', '<', $firstMonth.'-01')->sum('net_amount');
        $historyMonths = collect($calculator->monthKeys())
            ->filter(fn ($key) => $key < $firstMonth && $calculator->month($key)->isWorked)->count();

        // Les recettes du mois coupées au revenu total (spec 2026-10-12, §3.2).
        $monthRevenue = $totalRevenue;
        $priorRevenue = $historyRevenue + $prior->sum(fn ($s) => (float) ($s->figures['revenue'] ?? 0) + (float) ($s->figures['recovered'] ?? 0));
        $within = round(min($monthRevenue, max(0.0, ContractTargets::revenue($contract) - $priorRevenue)), 2);
        $beyond = round($monthRevenue - $within, 2);

        $due = [];
        $outstanding = [];
        $ceiling = [];
        foreach (array_keys(ChargeLedger::LINES) as $line) {
            $opening = (float) ($first?->{"opening_{$line}"} ?? 0);
            // Plafonné au total de la ligne : au-delà de la durée, plus rien n'est dû (§3.1).
            $alreadyDue = $historyMonths * $lineAmounts[$line] + $prior->sum(fn ($s) => $this->line($s, $line, 'due'));
            $due[$line] = $month->isWorked ? round(max(0.0, min($lineAmounts[$line], $lineTargets[$line] - $alreadyDue)), 2) : 0.0;
            $carried = $prior->sum(fn ($s) => $this->line($s, $line, 'due') - $this->line($s, $line, 'deducted'));
            $outstanding[$line] = round($opening + $carried + $due[$line], 2);
            // Revenu total atteint : les recettes au-delà règlent tout ce qui reste jusqu'au
            // total de la ligne, même pas encore dû (§3.3).
            $alreadyDeducted = $historyMonths * $lineAmounts[$line] - $opening + $prior->sum(fn ($s) => $this->line($s, $line, 'deducted'));
            $ceiling[$line] = $beyond > 0
                ? round(max($outstanding[$line], $lineTargets[$line] - $alreadyDeducted), 2)
                : $outstanding[$line];
        }

        // D'abord les restes à recouvrer sur la part sous le revenu total, puis le reste
        // jusqu'au plafond sur la part au-delà, dans l'ordre des lignes.
        $proposed = ChargeLedger::propose($outstanding, $within);
        if ($beyond > 0) {
            // Bornée au plafond TOTAL : une ligne déjà prélevée au-delà de son total a un
            // plafond négatif, que le `max(0, …)` par ligne ne retrancherait plus (relecture finale).
            $more = ChargeLedger::propose(
                collect($ceiling)->map(fn ($c, $line) => max(0.0, $c - $proposed[$line]))->all(),
                min($beyond, max(0.0, array_sum($ceiling) - array_sum($proposed))),
            );
            $proposed = collect($proposed)->map(fn ($p, $line) => round($p + $more[$line], 2))->all();
        }

        $charges = [];
        foreach (ChargeLedger::LINES as $line => $label) {
            $saved = $statement?->{"deducted_{$line}"};
            $charges[] = [
                'key' => $line,
                'label' => $label,
                'due' => $due[$line],
                'outstanding' => $outstanding[$line],
                'ceiling' => $ceiling[$line],
                'proposed' => $proposed[$line],
                'deducted' => $saved !== null ? (float) $saved : $proposed[$line],
            ];
        }
        $deductedTotal = (float) collect($charges)->sum('deducted');

        // Les charges se prennent d'abord sur la part sous le revenu total, dans la limite des
        // restes à recouvrer ; le reste vient de la part au-delà (§3.4).
        $fromWithin = min($deductedTotal, $within, max(0.0, array_sum($outstanding)));
        $balanceDue = round($within - $fromWithin, 2);
        $agencyShare = round($beyond - ($deductedTotal - $fromWithin), 2);

        $opening = $first ? (float) $first->opening_internet + (float) $first->opening_spotify + (float) $first->opening_manager : 0.0;

        $cumulativeRevenue = $priorRevenue + $monthRevenue;
        $cumulativeCharges = ($historyMonths * $contract->total_charges - $opening)
            + $prior->sum(fn ($s) => (float) ($s->figures['deducted_total'] ?? 0))
            + $deductedTotal;

        return new StatementFigures(
            month: $monthKey,
            ownerName: (string) $contract->vehicle?->owner?->name,
            vehicleNumber: (string) $contract->vehicle?->vehicle_number,
            contractMonths: (int) $contract->contract_months,
            startDate: $contract->start_date->toDateString(),
            businessDays: $month->businessDays,
            pauseDays: $month->pauseDays,
            immobilizationDays: $month->immobilizationDays,
            countedDays: $countedDays,
            dailyAmount: $dailyAmount,
            revenue: $revenue,
            recovered: $recovered,
            pendingCount: $pendingCount,
            pendingAmount: $month->pendingOnCountedAmount + (float) $late->sum('net_amount'),
            charges: $charges,
            deductedTotal: $deductedTotal,
            balanceDue: $balanceDue,
            revenueWithinTarget: $within,
            revenueBeyondTarget: $beyond,
            agencyShare: $agencyShare,
            cumulativeRevenue: $cumulativeRevenue,
            cumulativeCharges: $cumulativeCharges,
            cumulativeNet: $cumulativeRevenue - $cumulativeCharges,
            workedMonths: $calculator->workedMonthsUntil($monthKey),
            pauseDaysTaken: $calculator->pauseDaysTakenUntil($monthKey),
            pauseAllowance: 2 * (int) $contract->contract_months,
            isFirstStatement: $statement !== null && $first !== null && $first->is($statement),
            anomalies: $this->anomalies($month),
            calendarDays: $month->countedDays,
            recoveredCount: $recoveredPayments->count(),
            totalRevenue: $totalRevenue,
            receivedAmount: $receivedAmount,
            gap: $gap,
            gapCauses: abs($gap) >= 0.01 ? $this->gapCauses($month, $gap, $revenuePayments->concat($recoveredPayments), $dailyAmount) : [],
            revenuePaymentIds: $revenuePayments->pluck('id')->values()->all(),
            recoveredPaymentIds: $recoveredPayments->pluck('id')->values()->all(),
        );
    }

    /** La première fiche non annulée du contrat — celle qui porte le reste d'ouverture. */
    private function firstStatement(VehicleContract $contract, ?RemunerationStatement $statement): ?RemunerationStatement
    {
        $first = RemunerationStatement::query()
            ->where('vehicle_contract_id', $contract->id)
            ->where('status', '!=', 'cancelled')
            ->orderBy('month')
            ->first();

        // La fiche en cours de saisie porte ses propres valeurs d'ouverture, pas encore
        // enregistrées : c'est elle qu'on lit quand elle est la première.
        if ($statement && (! $first || $first->is($statement) || $statement->month->lt($first->month))) {
            return $statement;
        }

        return $first;
    }

    private function line(RemunerationStatement $statement, string $line, string $field): float
    {
        return (float) (collect($statement->figures['charges'] ?? [])->firstWhere('key', $line)[$field] ?? 0);
    }

    /** @return list<string> */
    /**
     * Ce qui explique un écart entre les jours et l'argent reçu (spec 2026-10-22, §3.4).
     *
     * @param  Collection<int, Payment>  $counted  les paiements comptés (recettes et recouvrés)
     * @return list<string>
     */
    private function gapCauses(ContractMonthFigures $month, float $gap, Collection $counted, float $dailyAmount): array
    {
        $causes = [];

        if ($month->countedDaysWithoutPayment > 0) {
            $causes[] = "{$month->countedDaysWithoutPayment} jour(s) comptabilisé(s) sans paiement";
        }
        if ($month->paymentsOnStoppedDays > 0) {
            $causes[] = "{$month->paymentsOnStoppedDays} paiement(s) sur un jour de pause ou d'immobilisation";
        }
        $otherAmounts = $counted->filter(fn ($p) => abs((float) $p->net_amount - $dailyAmount) >= 0.01)->count();
        if ($otherAmounts > 0) {
            $causes[] = "Montants différents du montant journalier : {$otherAmounts} paiement(s)";
        }

        return $causes !== [] ? $causes : ['Écart de '.number_format($gap, 0, ',', ' ').' F entre les jours et l\'argent reçu'];
    }

    private function anomalies(ContractMonthFigures $month): array
    {
        $anomalies = [];

        if ($month->paymentsOnStoppedDays > 0) {
            $anomalies[] = "{$month->paymentsOnStoppedDays} paiement(s) tombent sur des jours de pause ou d'immobilisation.";
        }
        if ($month->countedDaysWithoutPayment > 0) {
            $anomalies[] = "{$month->countedDaysWithoutPayment} jour(s) comptabilisé(s) sans paiement.";
        }

        return $anomalies;
    }
}
