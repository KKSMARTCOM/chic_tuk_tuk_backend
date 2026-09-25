<?php

namespace App\Domains\Fleet\Application\Data;

use App\Models\Payment;
use App\Models\VehicleContract;
use App\Shared\Data\BaseData;

/** Le contrat propriétaire-véhicule en cours, sur la fiche du véhicule. */
final class AdminVehicleActiveContractData extends BaseData
{
    public function __construct(
        public string $id,
        public float $totalAmount,
        public float $monthlyPayment,
        public float $totalPaid,
        public float $remainingAmount,
        public float $surplus,
        public int $progressPercentage,
        public ?string $startDate,
        public ?string $endDate,
        /** @var array<int, AdminVehiclePaymentData> */
        public array $recentPayments,
        public int $paymentsCount,
    ) {}

    public static function fromModel(VehicleContract $contract): self
    {
        return new self(
            id: $contract->id,
            totalAmount: (float) $contract->total_amount,
            monthlyPayment: (float) $contract->monthly_payment,
            totalPaid: $contract->total_paid,
            remainingAmount: $contract->remaining_amount,
            surplus: $contract->surplus,
            progressPercentage: $contract->progress_percentage,
            startDate: $contract->start_date?->toDateString(),
            endDate: $contract->end_date?->toDateString(),
            recentPayments: $contract->payments
                ->sortByDesc('payment_date')
                ->take(5)
                ->map(fn (Payment $payment) => AdminVehiclePaymentData::fromModel($payment))
                ->values()
                ->all(),
            paymentsCount: $contract->payments->count(),
        );
    }
}
