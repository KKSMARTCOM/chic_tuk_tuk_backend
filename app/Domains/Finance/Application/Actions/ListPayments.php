<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Application\Data\AdminPaymentData;
use App\Domains\Finance\Application\Data\AdminPaymentDriverOptionData;
use App\Domains\Finance\Application\Data\AdminPaymentPageData;
use App\Domains\Finance\Application\Data\AdminPaymentStatsData;
use App\Models\Commission;
use App\Models\Driver;
use App\Models\Payment;
use Carbon\Carbon;

/** La liste des paiements — ex-Admin\PaymentController::index(). */
final class ListPayments
{
    /** @param  array<string, ?string>  $filters  driver_id, status, payment_type, search, date_from, date_to */
    public function __invoke(array $filters = []): AdminPaymentPageData
    {
        $payments = $this->getAllPayments($filters);
        $payments->load(['vehicleContract.vehicle', 'driverContract.vehicle']);

        return new AdminPaymentPageData(
            payments: $payments->map(fn (Payment $payment) => AdminPaymentData::fromModel($payment))->all(),
            stats: AdminPaymentStatsData::fromStats($this->getPaymentStats()),
            drivers: Driver::with('user')->get()
                ->sortBy(fn (Driver $driver) => $driver->user?->name)
                ->map(fn (Driver $driver) => AdminPaymentDriverOptionData::fromModel($driver))
                ->values()
                ->all(),
        );
    }

    /**
     * Récupérer tous les paiements avec filtres
     */
    private function getAllPayments($filters = [])
    {
        $query = Payment::query()->with(['driver.user'])->latest('payment_date');

        if (isset($filters['driver_id']) && ! empty($filters['driver_id'])) {
            $query->where('driver_id', $filters['driver_id']);
        }

        if (isset($filters['status']) && ! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['payment_type']) && ! empty($filters['payment_type'])) {
            $query->where('payment_type', $filters['payment_type']);
        }

        // Groupé (2026-09-26) : sans parenthèses, le `orWhere` sur la référence échappait
        // à tous les autres filtres — agent, statut, type, dates.
        if (isset($filters['search']) && ! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->whereHas('driver.user', fn ($u) => $u->where('name', 'ilike', '%'.$search.'%'))
                    ->orWhere('reference_number', 'ilike', '%'.$search.'%');
            });
        }

        if (isset($filters['date_from']) && ! empty($filters['date_from'])) {
            $query->whereDate('payment_date', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to']) && ! empty($filters['date_to'])) {
            $query->whereDate('payment_date', '<=', $filters['date_to']);
        }

        return $query->latest()->get();
    }

    /**
     * Obtenir les statistiques des paiements
     */
    private function getPaymentStats()
    {
        $validatedCommissionAmount = Payment::where('payment_type', 'commission')
            ->where('status', 'completed')
            ->sum('amount');
        $totalCommissionCount = Payment::where('payment_type', 'commission')
            ->where('status', 'completed')
            ->count();
        $totalDue = Commission::where('status', 'active')->sum('amount');
        $totalPaidThisMonth = Payment::where('payment_type', 'commission')
            ->whereMonth('payment_date', Carbon::now()->month)
            ->whereYear('payment_date', Carbon::now()->year)
            ->where('status', 'completed')
            ->sum('amount');

        $validatedPaymentsCount = Payment::where('payment_type', 'contract')
            ->where('status', 'completed')
            ->count();
        $validatedPaymentsAmount = Payment::where('payment_type', 'contract')
            ->where('status', 'completed')
            ->sum('amount');
        $pendingPaymentsCount = Payment::where('payment_type', 'contract')
            ->where('status', 'pending')
            ->count();
        $pendingPaymentsAmount = Payment::where('payment_type', 'contract')
            ->where('status', 'pending')
            ->sum('amount');
        $cancelledPaymentsAmount = Payment::where('payment_type', 'contract')
            ->where('status', 'cancelled')
            ->sum('amount');
        $cancelledPaymentsCount = Payment::where('payment_type', 'contract')
            ->where('status', 'cancelled')
            ->count();

        return [
            // Type = commission
            'total_paid' => $validatedCommissionAmount,
            'total_paid_commission' => $validatedCommissionAmount,
            'total_due' => $totalDue,
            'balance_due' => $totalDue - $validatedCommissionAmount,
            'paid_this_month' => $totalPaidThisMonth,
            'payments_count' => $totalCommissionCount,

            // Type = contrat
            'validated_payments_amount' => $validatedPaymentsAmount,
            'validated_payments_count' => $validatedPaymentsCount,
            'pending_payments_amount' => $pendingPaymentsAmount,
            'pending_payments_count' => $pendingPaymentsCount,
            'cancelled_payments_amount' => $cancelledPaymentsAmount,
            'cancelled_payments_count' => $cancelledPaymentsCount,
        ];
    }
}
