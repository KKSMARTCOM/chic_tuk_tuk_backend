<?php

namespace App\Domains\Workforce\Application\Data;

use App\Shared\Data\BaseData;

/**
 * Le cadre « Revenus abonnements » du dossier agent —
 * CommissionService::getDriverSubscriptionRevenue().
 */
final class AdminDriverSubscriptionRevenueData extends BaseData
{
    public function __construct(
        public float $totalDue,
        public float $totalPaid,
        public float $balanceDue,
        /** @var array<int, array{subscription_id: string, booking_number: string, bookings_count: int, amount: float}> */
        public array $subscriptions,
    ) {}

    public static function fromArray(array $revenue): self
    {
        return new self(
            totalDue: $revenue['total_due'],
            totalPaid: $revenue['total_paid'],
            balanceDue: $revenue['balance_due'],
            subscriptions: $revenue['subscriptions']->values()->all(),
        );
    }
}
