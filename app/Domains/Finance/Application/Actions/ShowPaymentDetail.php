<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Application\Data\AdminDriverPaymentSummaryData;
use App\Domains\Finance\Application\Data\AdminPaymentData;
use App\Domains\Finance\Application\Data\AdminPaymentDetailData;
use App\Models\Payment;
use App\Services\PaymentService;

/** La fiche d'un paiement — ex-Admin\PaymentController::show(). */
final class ShowPaymentDetail
{
    public function __construct(private readonly PaymentService $paymentService) {}

    public function __invoke(string $paymentId): AdminPaymentDetailData
    {
        $payment = Payment::with(['driver.user', 'vehicleContract.vehicle', 'driverContract.vehicle'])->findOrFail($paymentId);

        return new AdminPaymentDetailData(
            payment: AdminPaymentData::fromModel($payment),
            driverSummary: AdminDriverPaymentSummaryData::fromStats($this->paymentService->getDriverPayments($payment->driver_id)),
        );
    }
}
