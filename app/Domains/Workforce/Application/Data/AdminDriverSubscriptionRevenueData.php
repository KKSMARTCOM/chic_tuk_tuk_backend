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
        /** @var array<int, AdminDriverSubscriptionRevenueItemData> */
        public array $subscriptions,
    ) {}

    public static function fromArray(array $revenue): self
    {
        return new self(
            totalDue: $revenue['total_due'],
            totalPaid: $revenue['total_paid'],
            balanceDue: $revenue['balance_due'],
            subscriptions: $revenue['subscriptions']->map(fn (array $s) => new AdminDriverSubscriptionRevenueItemData(
                subscriptionId: $s['subscription_id'],
                bookingNumber: $s['booking_number'],
                bookingsCount: $s['bookings_count'],
                amount: (float) $s['amount'],
            ))->values()->all(),
        );
    }
}
