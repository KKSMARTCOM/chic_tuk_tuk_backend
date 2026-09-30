<?php

namespace App\Domains\Finance\Domain;

use App\Models\LeaveRequest;
use App\Models\VehicleContract;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Les chiffres mensuels d'un contrat véhicule — SEUL endroit où ces règles s'écrivent
 * (spec 2026-09-30, §3).
 *
 * Charge une fois les pauses et les paiements du contrat, puis calcule chaque mois à la
 * demande, en mémoire. Le calendrier fait foi (§3.1) : les jours viennent des pauses, et
 * les paiements lui sont RAPPROCHÉS — un écart devient une anomalie, jamais une
 * correction silencieuse.
 *
 * ⚠️ Deux sources pour les pauses d'agent (§3.2, décision du 2026-09-30) : les pauses
 * véhicule `agent_leave` ET les pauses d'agent elles-mêmes. Une pause saisie après coup
 * (`AddHistoricalLeave`) n'a jamais de pause véhicule ; ne lire que `vehicle_pauses` en
 * ferait des jours comptabilisés. Le classement par jour empêche de compter deux fois une
 * pause d'agent ET sa pause véhicule automatique.
 */
final class ContractMonthCalculator
{
    /** @var array<string, ContractMonthFigures> */
    private array $months = [];

    private function __construct(
        private readonly VehicleContract $contract,
        private readonly Carbon $today,
        private readonly Collection $pauses,
        private readonly Collection $payments,
    ) {}

    public static function for(VehicleContract $contract, ?Carbon $today = null): self
    {
        $agentPauses = LeaveRequest::query()
            ->whereIn('driver_contract_id', $contract->driverContracts()->pluck('id'))
            ->whereIn('status', ['ongoing', 'completed'])
            ->get()
            ->map(fn (LeaveRequest $leave) => (object) [
                'start_date' => $leave->start_date,
                'end_date' => $leave->end_date,
                'reason_type' => 'agent_leave',
            ]);

        return new self(
            $contract,
            ($today ?? Carbon::today())->copy()->startOfDay(),
            $contract->pauses()->get()->concat($agentPauses),
            $contract->payments()->get(),
        );
    }

    /** @return list<string> */
    public function monthKeys(): array
    {
        $bound = $this->bound();
        $keys = [];

        for ($month = $this->contract->start_date->copy()->startOfMonth(); $month->lte($bound); $month->addMonth()) {
            $keys[] = $month->format('Y-m');
        }

        return $keys;
    }

    public function month(string $monthKey): ContractMonthFigures
    {
        return $this->months[$monthKey] ??= $this->compute($monthKey);
    }

    public function workedMonths(): int
    {
        return collect($this->monthKeys())->filter(fn ($key) => $this->month($key)->isWorked)->count();
    }

    public function pauseDaysTaken(): int
    {
        return collect($this->monthKeys())->sum(fn ($key) => $this->month($key)->pauseDays);
    }

    /** Les mois travaillés jusqu'à ce mois inclus — le « 02 | 30 » de la fiche. */
    public function workedMonthsUntil(string $monthKey): int
    {
        return collect($this->monthKeys())->filter(fn ($key) => $key <= $monthKey && $this->month($key)->isWorked)->count();
    }

    public function pauseDaysTakenUntil(string $monthKey): int
    {
        return collect($this->monthKeys())->filter(fn ($key) => $key <= $monthKey)->sum(fn ($key) => $this->month($key)->pauseDays);
    }

    /** Le dernier jour compté : aujourd'hui, ou la fin du contrat si elle est passée. */
    private function bound(): Carbon
    {
        $end = $this->contract->end_date;

        return $end !== null && $end->lt($this->today) ? $end->copy()->startOfDay() : $this->today;
    }

    private function compute(string $monthKey): ContractMonthFigures
    {
        $monthStart = Carbon::parse($monthKey.'-01')->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth()->startOfDay();
        $contractStart = $this->contract->start_date->copy()->startOfDay();
        $bound = $this->bound();

        // Comparaisons explicites : `max()` et `min()` de PHP ne comparent pas des
        // instances Carbon de façon fiable.
        $from = $contractStart->gt($monthStart) ? $contractStart : $monthStart;
        $to = $bound->lt($monthEnd) ? $bound : $monthEnd;

        $days = ContractMonthCalendar::classify($from, $to, $this->pauses);

        $monthPayments = $this->payments->filter(
            fn ($p) => $p->payment_month !== null && $p->payment_month->format('Y-m') === $monthKey,
        );
        $live = $monthPayments->where('status', '!=', 'cancelled');
        $paidDates = $live->map(fn ($p) => $p->payment_date->toDateString())->unique();

        return new ContractMonthFigures(
            month: $monthKey,
            isCurrent: $monthStart->format('Y-m') === $this->today->format('Y-m'),
            isWorked: $days->countedDays > 0,
            businessDays: $days->businessDays,
            pauseDays: $days->pauseDays,
            immobilizationDays: $days->immobilizationDays,
            countedDays: $days->countedDays,
            validatedAmount: (float) $monthPayments->where('status', 'completed')->sum('net_amount'),
            pendingCount: $monthPayments->where('status', 'pending')->count(),
            pendingAmount: (float) $monthPayments->where('status', 'pending')->sum('net_amount'),
            cancelledAmount: (float) $monthPayments->where('status', 'cancelled')->sum('net_amount'),
            paymentsOnStoppedDays: $live->filter(fn ($p) => in_array($p->payment_date->toDateString(), $days->stoppedDates, true))->count(),
            countedDaysWithoutPayment: collect($days->countedDates)->diff($paidDates)->count(),
        );
    }
}
