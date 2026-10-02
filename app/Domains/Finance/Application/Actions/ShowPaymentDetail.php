<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Application\Data\AdminPaymentData;
use App\Domains\Finance\Application\Data\AdminPaymentDetailData;
use App\Models\Payment;

/** La fiche d'un paiement — ex-Admin\PaymentController::show(). */
final class ShowPaymentDetail
{
    public function __construct(private readonly BuildDriverSituation $buildSituation) {}

    public function __invoke(string $paymentId): AdminPaymentDetailData
    {
        $payment = Payment::with(['driver.user', 'vehicleContract.vehicle', 'driverContract.vehicle'])->findOrFail($paymentId);

        return new AdminPaymentDetailData(
            payment: AdminPaymentData::fromModel($payment),
            driverSituation: ($this->buildSituation)($payment->driver_id),
        );
    }
}
