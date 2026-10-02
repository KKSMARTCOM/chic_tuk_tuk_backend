<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;

/** Ce que l'agent DOIT à l'agence : ses commissions actives, moins ce qu'il en a payé. */
final class AdminDriverCommissionSituationData extends BaseData
{
    public function __construct(
        public float $due,
        public float $paid,
        public float $balance,
        public int $activeCount,
        /** Ce que l'agent a gagné sur toutes ses courses terminées. */
        public float $driverEarning,
    ) {}
}
