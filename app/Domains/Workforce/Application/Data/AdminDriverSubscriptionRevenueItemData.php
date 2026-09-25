<?php

namespace App\Domains\Workforce\Application\Data;

use App\Shared\Data\BaseData;

/** Un abonnement du cadre « Revenus abonnements » — voir AdminDriverSubscriptionRevenueData. */
final class AdminDriverSubscriptionRevenueItemData extends BaseData
{
    public function __construct(
        public string $subscriptionId,
        public string $bookingNumber,
        public int $bookingsCount,
        public float $amount,
    ) {}
}
