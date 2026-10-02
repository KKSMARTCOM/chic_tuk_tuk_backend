<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;

/** Ce que l'agence DOIT à l'agent sur ses abonnements — `ComputeDriverSubscriptionRevenue`. */
final class AdminDriverSubscriptionSituationData extends BaseData
{
    public function __construct(
        public int $count,
        public int $completedBookings,
        public float $due,
        public float $paid,
        public float $balance,
    ) {}
}
