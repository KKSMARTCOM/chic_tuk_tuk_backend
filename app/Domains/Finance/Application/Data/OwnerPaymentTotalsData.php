<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;

/** Les totaux nets de la liste des paiements du propriétaire, sur TOUT le filtre. */
final class OwnerPaymentTotalsData extends BaseData
{
    public function __construct(
        public float $paid,
        public float $pending,
    ) {}
}
