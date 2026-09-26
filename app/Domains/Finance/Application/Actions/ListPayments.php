<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Application\Data\AdminPaymentData;
use App\Domains\Finance\Application\Data\AdminPaymentDriverOptionData;
use App\Domains\Finance\Application\Data\AdminPaymentPageData;
use App\Domains\Finance\Application\Data\AdminPaymentStatsData;
use App\Models\Driver;
use App\Models\Payment;
use App\Services\PaymentService;

/** La liste des paiements — ex-Admin\PaymentController::index(). */
final class ListPayments
{
    public function __construct(private readonly PaymentService $paymentService) {}

    /** @param  array<string, ?string>  $filters  driver_id, status, payment_type, search, date_from, date_to */
    public function __invoke(array $filters = []): AdminPaymentPageData
    {
        $payments = $this->paymentService->getAllPayments($filters);
        $payments->load(['vehicleContract.vehicle', 'driverContract.vehicle']);

        return new AdminPaymentPageData(
            payments: $payments->map(fn (Payment $payment) => AdminPaymentData::fromModel($payment))->all(),
            stats: AdminPaymentStatsData::fromStats($this->paymentService->getPaymentStats()),
            drivers: Driver::with('user')->get()
                ->sortBy(fn (Driver $driver) => $driver->user?->name)
                ->map(fn (Driver $driver) => AdminPaymentDriverOptionData::fromModel($driver))
                ->values()
                ->all(),
        );
    }
}
