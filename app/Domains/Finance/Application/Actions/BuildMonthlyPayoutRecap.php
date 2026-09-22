<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Application\Data\MonthlyPayoutData;
use App\Models\LeaveRequest;
use App\Models\VehicleContract;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Le récapitulatif mensuel d'un contrat véhicule.
 *
 * Transposition à l'identique de ce qu'OwnerVehicleController::buildMonthlyRecap
 * calculait sur le chemin Blade. Ce contrôleur a été supprimé à la bascule du
 * 2026-09-18 ; les chiffres ont été confrontés un à un entre les deux écrans, sur un
 * véhicule réel de staging, avant de le retirer.
 *
 * Rangée dans Finance parce qu'elle répond à une question d'argent ; elle lit du Fleet
 * (pauses véhicule) et du Workforce (pauses d'agent) pour y répondre.
 *
 * Trois règles portées par le code d'origine, à ne pas perdre :
 *
 *   1. le mois courant figure TOUJOURS, même sans paiement déjà généré ;
 *   2. les mois futurs sont exclus ;
 *   3. pour le mois courant, la borne de comptage des jours est AUJOURD'HUI et non la
 *      fin du mois — sinon les jours de pause à venir seraient déjà décomptés.
 */
final class BuildMonthlyPayoutRecap
{
    /** @return Collection<int, MonthlyPayoutData> */
    public function __invoke(VehicleContract $contract): Collection
    {
        $contract->load('payments');

        $payments = $contract->payments;
        $today = Carbon::today();

        $months = $payments
            ->map(fn ($payment) => Carbon::parse($payment->payment_month)->format('Y-m'))
            ->unique()
            ->sort();

        $currentMonthKey = $today->format('Y-m');
        if (! $months->contains($currentMonthKey)) {
            $months = $months->push($currentMonthKey);
        }

        $vehiclePauses = $contract->pauses()->get();
        $driverContractIds = $contract->driverContracts()->pluck('id');
        $leaveRequests = LeaveRequest::whereIn('driver_contract_id', $driverContractIds)
            ->whereIn('status', ['completed', 'ongoing'])
            ->get();

        return $months
            ->map(function (string $monthKey) use ($payments, $contract, $today, $vehiclePauses, $leaveRequests) {
                $monthStart = Carbon::parse($monthKey.'-01')->startOfDay();
                $monthEnd = $monthStart->copy()->endOfMonth();

                if ($monthStart->gt($today)) {
                    return null;
                }

                $effectiveMonthEnd = min($monthEnd, $today);

                $monthPayments = $payments->filter(
                    fn ($payment) => Carbon::parse($payment->payment_month)->format('Y-m') === $monthKey,
                );

                $workedDays = $monthPayments->pluck('payment_date')
                    ->map(fn ($date) => Carbon::parse($date)->format('Y-m-d'))
                    ->unique()
                    ->count();

                $validated = (float) $monthPayments->where('status', 'completed')->sum('net_amount');

                return new MonthlyPayoutData(
                    month: $monthKey,
                    isCurrent: $monthStart->isSameMonth($today) && $monthStart->isSameYear($today),
                    validatedAmount: $validated,
                    pendingAmount: (float) $monthPayments->where('status', 'pending')->sum('net_amount'),
                    cancelledAmount: (float) $monthPayments->where('status', 'cancelled')->sum('net_amount'),
                    totalCharges: $contract->total_charges,
                    fixedAmount: $validated - $contract->total_charges,
                    workedDays: $workedDays,
                    agentLeaveDays: $this->businessDaysInMonth($leaveRequests, $monthStart, $effectiveMonthEnd),
                    immobilizationDays: $this->businessDaysInMonth($vehiclePauses, $monthStart, $effectiveMonthEnd),
                );
            })
            ->filter()
            ->sortByDesc('month')
            ->values();
    }

    /**
     * Les jours ouvrés de ces intervalles qui tombent dans le mois.
     *
     * Pauses d'agent et pauses véhicule portent les mêmes colonnes `start_date` et
     * `end_date` et se comptent de la même façon ; le code d'origine écrivait deux fois
     * la même boucle, elle est factorisée ici sans changer son résultat.
     *
     * Une fin de période absente vaut « aujourd'hui » : c'est ce que fait le code
     * d'origine, et c'est ce qui rend une pause en cours comptable.
     *
     * @param  Collection<int, object>  $intervals
     */
    private function businessDaysInMonth(Collection $intervals, Carbon $monthStart, Carbon $effectiveMonthEnd): int
    {
        return (int) $intervals->sum(function ($interval) use ($monthStart, $effectiveMonthEnd) {
            $start = $interval->start_date;
            $end = $interval->end_date ?? Carbon::today();

            $overlapStart = $start->greaterThan($monthStart) ? $start : $monthStart;
            $overlapEnd = $end->lessThan($effectiveMonthEnd) ? $end : $effectiveMonthEnd;

            if ($overlapStart->gt($overlapEnd)) {
                return 0;
            }

            return LeaveRequest::countBusinessDays($overlapStart, $overlapEnd);
        });
    }
}
