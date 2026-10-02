<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Application\Data\AdminDriverCommissionSituationData;
use App\Domains\Finance\Application\Data\AdminDriverContractSituationData;
use App\Domains\Finance\Application\Data\AdminDriverSituationData;
use App\Domains\Finance\Application\Data\AdminDriverSubscriptionSituationData;
use App\Models\Booking;
use App\Models\Commission;
use App\Models\DriverContract;
use App\Models\Payment;
use Carbon\Carbon;

/**
 * La situation d'un agent (2026-10-02) : commissions, paiements de contrat, abonnements.
 * Remplace le « Résumé de l'agent », qui ne parlait que des commissions sans le dire et
 * comptait tous ses paiements, de toute nature, sous « N paiement(s) ».
 */
final class BuildDriverSituation
{
    public function __construct(private readonly ComputeDriverSubscriptionRevenue $subscriptionRevenue) {}

    public function __invoke(string $driverId): AdminDriverSituationData
    {
        return new AdminDriverSituationData(
            commissions: $this->commissions($driverId),
            contract: $this->contract($driverId),
            subscriptions: $this->subscriptions($driverId),
        );
    }

    /** Commissions ACTIVES et paiements de commission VALIDÉS : les annulés ne comptent nulle part. */
    private function commissions(string $driverId): AdminDriverCommissionSituationData
    {
        $active = Commission::query()->where('driver_id', $driverId)->where('status', 'active');
        $due = (float) (clone $active)->sum('amount');
        $paid = (float) Payment::query()->where('driver_id', $driverId)
            ->where('payment_type', 'commission')->where('status', 'completed')->sum('amount');

        return new AdminDriverCommissionSituationData(
            due: $due,
            paid: $paid,
            balance: $due - $paid,
            activeCount: (clone $active)->count(),
            driverEarning: (float) Booking::query()->where('driver_id', $driverId)->where('status', 'completed')->sum('driver_earning'),
        );
    }

    /**
     * Le contrat EN COURS, à défaut le dernier (décidé le 2026-10-02) : ce qu'on veut savoir
     * pour agir aujourd'hui. Les contrats précédents ne comptent pas.
     */
    private function contract(string $driverId): ?AdminDriverContractSituationData
    {
        $contract = DriverContract::query()->with('vehicle')
            ->where('driver_id', $driverId)
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('start_date')
            ->first();
        if (! $contract) {
            return null;
        }

        $payments = Payment::query()->where('driver_contract_id', $contract->id)->where('payment_type', 'contract')->get();
        $validated = $payments->where('status', 'completed');
        $pending = $payments->where('status', 'pending');
        $today = Carbon::today();
        $late = $pending->filter(fn (Payment $p) => $p->payment_date !== null && Carbon::parse($p->payment_date)->lt($today));
        $lastCollected = $validated->pluck('collected_on')->filter()->max();

        return new AdminDriverContractSituationData(
            vehicleNumber: $contract->vehicle?->vehicle_number,
            startDate: Carbon::parse($contract->start_date)->toDateString(),
            isActive: $contract->status === 'active',
            validatedCount: $validated->count(),
            validatedAmount: (float) $validated->sum('amount'),
            pendingCount: $pending->count(),
            pendingAmount: (float) $pending->sum('amount'),
            lateCount: $late->count(),
            lateAmount: (float) $late->sum('amount'),
            lastCollectedOn: $lastCollected ? Carbon::parse($lastCollected)->toDateString() : null,
        );
    }

    private function subscriptions(string $driverId): AdminDriverSubscriptionSituationData
    {
        $revenue = ($this->subscriptionRevenue)($driverId);

        return new AdminDriverSubscriptionSituationData(
            count: $revenue['subscriptions']->count(),
            completedBookings: (int) $revenue['subscriptions']->sum('bookings_count'),
            due: (float) $revenue['total_due'],
            paid: (float) $revenue['total_paid'],
            balance: (float) $revenue['balance_due'],
        );
    }
}
