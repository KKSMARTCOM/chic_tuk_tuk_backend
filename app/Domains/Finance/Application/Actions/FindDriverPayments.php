<?php

namespace App\Domains\Finance\Application\Actions;

use App\Models\Driver;

/**
 * Le récapitulatif des paiements d'un agent — ex-`PaymentService::getDriverPayments()`,
 * déplacé sans changement le 2026-09-27.
 */
final class FindDriverPayments
{
    /**
     * Obtenir les paiements d'un conducteur
     */
    public function __invoke(string $driverId)
    {
        $driver = Driver::with('user')->findOrFail($driverId);

        $totalDue = $driver->commissions()->where('status', 'active')->sum('amount');
        $totalPaid = $driver->payments()->where('payment_type', 'commission')->where('status', 'completed')->sum('amount');
        $balanceDue = $totalDue - $totalPaid;

        return [
            'driver' => $driver,
            'total_due' => $totalDue,
            'total_paid' => $totalPaid,
            'balance_due' => $balanceDue,
            'payments_count' => $driver->payments()->count(),
            'commissions_count' => $driver->commissions()->count(),
        ];
    }
}
