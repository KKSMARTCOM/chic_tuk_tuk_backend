<?php

namespace App\Domains\Finance\Application\Actions;

use App\Models\Booking;
use App\Models\Driver;

/**
 * Le cadre « Commissions » du dossier agent — ex-`CommissionService::getDriverCommissions()`,
 * déplacé sans changement le 2026-09-27.
 */
final class SummarizeDriverCommissions
{
    public function __invoke(string $driverId)
    {
        $driver = Driver::with('user')->findOrFail($driverId);

        $driverEarning = Booking::where('driver_id', $driverId)
            ->where('status', 'completed')
            ->sum('driver_earning');

        return [
            'driver' => $driver,
            'driver_earning' => $driverEarning,
            'commissions_count' => $driver->commissions()->count(),
            // Commissions DUES et paiements VALIDÉS seulement (2026-09-26) : les annulés
            // comptaient des deux côtés.
            'paid_revenue' => $paid = (float) $driver->payments()->where('payment_type', 'commission')->where('status', 'completed')->sum('amount'),
            'unpaid_revenue' => (float) $driver->commissions()->where('status', 'active')->sum('amount') - $paid,
        ];
    }
}
