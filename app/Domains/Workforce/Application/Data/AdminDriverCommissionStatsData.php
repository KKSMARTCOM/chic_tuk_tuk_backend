<?php

namespace App\Domains\Workforce\Application\Data;

use App\Shared\Data\BaseData;

/** Le cadre « Commissions » du dossier agent — CommissionService::getDriverCommissions(). */
final class AdminDriverCommissionStatsData extends BaseData
{
    public function __construct(
        public float $driverEarning,
        public float $unpaidRevenue,
        public float $paidRevenue,
    ) {}

    public static function fromArray(array $stats): self
    {
        return new self(
            driverEarning: (float) $stats['driver_earning'],
            unpaidRevenue: (float) $stats['unpaid_revenue'],
            paidRevenue: (float) $stats['paid_revenue'],
        );
    }
}
