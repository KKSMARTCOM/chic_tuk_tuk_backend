<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Application\Data\AdminDriverPaymentSummaryData;
use App\Domains\Finance\Application\Data\AdminPaymentData;
use App\Domains\Finance\Application\Data\AdminPaymentDetailData;
use App\Models\Payment;

/** La fiche d'un paiement — ex-Admin\PaymentController::show(). */
final class ShowPaymentDetail
{
    public function __construct(private readonly FindDriverPayments $findDriverPayments) {}

    public function __invoke(string $paymentId): AdminPaymentDetailData
    {
        $payment = Payment::with(['driver.user', 'vehicleContract.vehicle', 'driverContract.vehicle'])->findOrFail($paymentId);

        return new AdminPaymentDetailData(
            payment: AdminPaymentData::fromModel($payment),
            driverSummary: AdminDriverPaymentSummaryData::fromStats(($this->findDriverPayments)($payment->driver_id)),
        );
    }
}
