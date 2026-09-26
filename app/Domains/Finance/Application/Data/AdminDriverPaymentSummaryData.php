<?php

namespace App\Domains\Finance\Application\Data;

use App\Domains\Fleet\Application\Data\AdminVehicleContractPartyData;
use App\Shared\Data\BaseData;

/**
 * Le « Résumé de l'agent » : commissions DUES, paiements de commission VALIDÉS, et ce
 * qu'il reste — `PaymentService::getDriverPayments()`.
 */
final class AdminDriverPaymentSummaryData extends BaseData
{
    public function __construct(
        public AdminVehicleContractPartyData $driver,
        public float $totalDue,
        public float $totalPaid,
        public float $balanceDue,
        public int $paymentsCount,
        public int $commissionsCount,
    ) {}

    /** @param  array<string, mixed>  $stats */
    public static function fromStats(array $stats): self
    {
        $driver = $stats['driver'];

        return new self(
            driver: AdminVehicleContractPartyData::fromUser($driver->id, $driver->user),
            totalDue: (float) $stats['total_due'],
            totalPaid: (float) $stats['total_paid'],
            balanceDue: (float) $stats['balance_due'],
            paymentsCount: (int) $stats['payments_count'],
            commissionsCount: (int) $stats['commissions_count'],
        );
    }
}
