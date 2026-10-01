<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Domain\ChargeLedger;
use App\Domains\Finance\Domain\ContractMonthCalculator;
use App\Domains\Finance\Domain\ContractMonthFigures;
use App\Domains\Finance\Domain\StatementFigures;
use App\Models\Payment;
use App\Models\RemunerationStatement;
use App\Models\VehicleContract;
use Carbon\Carbon;

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
 */
final class BuildStatementFigures
{
    public function __invoke(VehicleContract $contract, string $monthKey, ?RemunerationStatement $statement = null): StatementFigures
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
        // En instance : en attente, OU validés mais encaissés après la date de la fiche.
        $late = $unattached->filter(fn ($p) => $p->payment_month->format('Y-m') === $monthKey && ! $collected($p));
        $revenue = (float) $revenuePayments->sum('net_amount');
        $recovered = (float) $recoveredPayments->sum('net_amount');

        $first = $this->firstStatement($contract, $statement);

        $lineAmounts = [
            'internet' => (float) ($contract->unlimited_internet ?? 0),
            'spotify' => (float) ($contract->spotify_premium ?? 0),
            'manager' => (float) ($contract->manager_remuneration ?? 0),
        ];

        $due = [];
        $outstanding = [];
        foreach (array_keys(ChargeLedger::LINES) as $line) {
            $due[$line] = $month->isWorked ? $lineAmounts[$line] : 0.0;
            $carried = $prior->sum(fn ($s) => $this->line($s, $line, 'due') - $this->line($s, $line, 'deducted'));
            $outstanding[$line] = round((float) ($first?->{"opening_{$line}"} ?? 0) + $carried + $due[$line], 2);
        }

        $proposed = ChargeLedger::propose($outstanding, $revenue + $recovered);
        $charges = [];
        foreach (ChargeLedger::LINES as $line => $label) {
            $saved = $statement?->{"deducted_{$line}"};
            $charges[] = [
                'key' => $line,
                'label' => $label,
                'due' => $due[$line],
                'outstanding' => $outstanding[$line],
                'proposed' => $proposed[$line],
                'deducted' => $saved !== null ? (float) $saved : $proposed[$line],
            ];
        }
        $deductedTotal = (float) collect($charges)->sum('deducted');

        $historyRevenue = (float) Payment::query()
            ->where('vehicle_contract_id', $contract->id)->where('status', 'completed')
            ->where('payment_month', '<', $firstMonth.'-01')->sum('net_amount');
        $historyMonths = collect($calculator->monthKeys())
            ->filter(fn ($key) => $key < $firstMonth && $calculator->month($key)->isWorked)->count();
        $opening = $first ? (float) $first->opening_internet + (float) $first->opening_spotify + (float) $first->opening_manager : 0.0;

        $cumulativeRevenue = $historyRevenue
            + $prior->sum(fn ($s) => (float) ($s->figures['revenue'] ?? 0) + (float) ($s->figures['recovered'] ?? 0))
            + $revenue + $recovered;
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
            countedDays: $month->countedDays,
            dailyAmount: $contract->daily_net_amount,
            revenue: $revenue,
            recovered: $recovered,
            pendingCount: $month->pendingCount + $late->count(),
            pendingAmount: $month->pendingAmount + (float) $late->sum('net_amount'),
            charges: $charges,
            deductedTotal: $deductedTotal,
            balanceDue: round($revenue + $recovered - $deductedTotal, 2),
            cumulativeRevenue: $cumulativeRevenue,
            cumulativeCharges: $cumulativeCharges,
            cumulativeNet: $cumulativeRevenue - $cumulativeCharges,
            workedMonths: $calculator->workedMonthsUntil($monthKey),
            pauseDaysTaken: $calculator->pauseDaysTakenUntil($monthKey),
            pauseAllowance: 2 * (int) $contract->contract_months,
            isFirstStatement: $statement !== null && $first !== null && $first->is($statement),
            anomalies: $this->anomalies($month, $cumulativeRevenue, (float) $contract->total_amount),
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
    private function anomalies(ContractMonthFigures $month, float $cumulativeRevenue, float $target): array
    {
        $anomalies = [];

        if ($month->paymentsOnStoppedDays > 0) {
            $anomalies[] = "{$month->paymentsOnStoppedDays} paiement(s) tombent sur des jours de pause ou d'immobilisation.";
        }
        if ($month->pendingCount > 0) {
            $anomalies[] = "{$month->pendingCount} paiement(s) du mois encore en attente.";
        }
        if ($month->countedDaysWithoutPayment > 0) {
            $anomalies[] = "{$month->countedDaysWithoutPayment} jour(s) comptabilisé(s) sans paiement.";
        }
        if ($target > 0 && $cumulativeRevenue > $target) {
            $anomalies[] = 'Le cumul des recettes dépasse le revenu cible du contrat.';
        }

        return $anomalies;
    }
}
