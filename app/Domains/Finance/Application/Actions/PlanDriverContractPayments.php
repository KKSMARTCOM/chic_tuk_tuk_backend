<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Domain\ContractPaymentPlanner;
use App\Domains\Finance\Domain\PaymentPlan;
use App\Models\DriverContract;
use App\Models\LeaveRequest;
use App\Models\Payment;
use App\Shared\Http\ApiException;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Le classement d'une période pour un contrat agent, données chargées (spec 2026-10-01, §3.2).
 *
 * ⚠️ Le contrat véhicule est celui DU CONTRAT AGENT, jamais le contrat actif du véhicule :
 * c'est ce qui rattache les jours d'un agent parti au contrat sur lequel il les a faits.
 * La période est ramenée aux dates des deux contrats.
 */
final class PlanDriverContractPayments
{
    public function __invoke(DriverContract $contract, Carbon $from, Carbon $to): PaymentPlan
    {
        $contract->loadMissing('vehicleContract');
        $vehicleContract = $contract->vehicleContract;
        if ($vehicleContract === null || $vehicleContract->daily_amount === null) {
            throw new ApiException(409, 'CONTRACT_WITHOUT_DAILY_AMOUNT', 'Le contrat véhicule de cet agent n\'a pas de versement journalier : corrigez le contrat d\'abord.');
        }

        $start = $contract->start_date->gt($vehicleContract->start_date) ? $contract->start_date : $vehicleContract->start_date;
        $end = $contract->end_date;
        if ($vehicleContract->end_date !== null && ($end === null || $vehicleContract->end_date->lt($end))) {
            $end = $vehicleContract->end_date;
        }

        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();
        $boundedFrom = $from->lt($start) ? $start->copy()->startOfDay() : $from;
        $boundedTo = $end !== null && $to->gt($end) ? $end->copy()->startOfDay() : $to;
        if ($boundedFrom->gt($boundedTo) || $boundedFrom->gt(Carbon::today())) {
            throw ValidationException::withMessages(['from' => 'La période est hors du contrat de l\'agent.']);
        }

        $agentPauses = LeaveRequest::query()
            ->where('driver_contract_id', $contract->id)
            ->whereIn('status', ['ongoing', 'completed'])
            ->get()
            ->map(fn (LeaveRequest $leave) => (object) [
                'start_date' => $leave->start_date,
                'end_date' => $leave->end_date,
                'reason_type' => 'agent_leave',
            ]);

        $payments = Payment::query()
            ->where('driver_contract_id', $contract->id)
            ->where('payment_type', 'contract')
            ->whereBetween('payment_date', [$boundedFrom->toDateString(), $boundedTo->toDateString()])
            ->get(['id', 'payment_date', 'status']);

        return ContractPaymentPlanner::plan(
            $boundedFrom, $boundedTo, $start, $end, Carbon::today(),
            $vehicleContract->pauses()->get()->concat($agentPauses),
            $payments,
        );
    }
}
