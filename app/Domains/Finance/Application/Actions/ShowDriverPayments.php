<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Application\Data\AdminCommissionData;
use App\Domains\Finance\Application\Data\AdminDriverPaymentsData;
use App\Domains\Finance\Application\Data\AdminDriverPaymentSummaryData;
use App\Domains\Finance\Application\Data\AdminPaymentData;
use App\Models\Commission;
use App\Models\Payment;
use App\Services\PaymentService;

/**
 * Les paiements d'un agent — ex-Admin\PaymentController::driverPaymentDetails().
 *
 * Le Blade paginait par 15 ; l'écran reçoit tout et pagine lui-même, un agent n'ayant
 * que quelques centaines de paiements par an.
 */
final class ShowDriverPayments
{
    public function __construct(private readonly PaymentService $paymentService) {}

    public function __invoke(string $driverId): AdminDriverPaymentsData
    {
        $summary = $this->paymentService->getDriverPayments($driverId);

        return new AdminDriverPaymentsData(
            summary: AdminDriverPaymentSummaryData::fromStats($summary),
            payments: Payment::where('driver_id', $driverId)
                ->with(['driver.user', 'vehicleContract.vehicle', 'driverContract.vehicle'])
                ->latest('payment_date')
                ->get()
                ->map(fn (Payment $payment) => AdminPaymentData::fromModel($payment))
                ->all(),
            commissions: $this->paymentService->getDriverDueCommissions($driverId)
                ->load('driver.user')
                ->map(fn (Commission $commission) => AdminCommissionData::fromModel($commission))
                ->all(),
        );
    }
}
