<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Application\Data\AdminCommissionData;
use App\Domains\Finance\Application\Data\AdminDriverPaymentsData;
use App\Domains\Finance\Application\Data\AdminDriverPaymentSummaryData;
use App\Domains\Finance\Application\Data\AdminPaymentData;
use App\Models\Commission;
use App\Models\Payment;

/**
 * Les paiements d'un agent — ex-Admin\PaymentController::driverPaymentDetails().
 *
 * Le Blade paginait par 15 ; l'écran reçoit tout et pagine lui-même, un agent n'ayant
 * que quelques centaines de paiements par an.
 */
final class ShowDriverPayments
{
    public function __construct(private readonly FindDriverPayments $findDriverPayments) {}

    public function __invoke(string $driverId): AdminDriverPaymentsData
    {
        $summary = ($this->findDriverPayments)($driverId);

        return new AdminDriverPaymentsData(
            summary: AdminDriverPaymentSummaryData::fromStats($summary),
            payments: Payment::where('driver_id', $driverId)
                ->with(['driver.user', 'vehicleContract.vehicle', 'driverContract.vehicle'])
                ->latest('payment_date')
                ->get()
                ->map(fn (Payment $payment) => AdminPaymentData::fromModel($payment))
                ->all(),
            commissions: $this->getDriverDueCommissions($driverId)
                ->load('driver.user')
                ->map(fn (Commission $commission) => AdminCommissionData::fromModel($commission))
                ->all(),
        );
    }

    /**
     * Récupérer les commissions dues d'un conducteur (non payées)
     */
    private function getDriverDueCommissions(string $driverId)
    {
        return Commission::where('driver_id', $driverId)
            ->where('status', 'active')
            ->with(['booking'])
            ->orderBy('date', 'desc')
            ->get();
    }
}
