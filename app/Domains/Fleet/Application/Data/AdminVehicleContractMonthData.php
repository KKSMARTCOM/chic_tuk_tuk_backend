<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;

/** Le total payé sur un contrat pour un mois, `month` au format « YYYY-MM ». */
final class AdminVehicleContractMonthData extends BaseData
{
    public function __construct(
        public string $month,
        public float $total,
    ) {}
}
