<?php

namespace App\Domains\Fleet\Application\Data;

use App\Models\VehicleContractTerm;
use App\Shared\Data\BaseData;

/** Une durée de contrat véhicule et ses montants, telle que l'écran de réglages l'affiche. */
final class VehicleContractTermData extends BaseData
{
    public function __construct(
        public int $months,
        public float $totalAmount,
        public float $dailyAmount,
        public float $dailyTax,
    ) {}

    public static function fromModel(VehicleContractTerm $term): self
    {
        return new self(
            months: $term->months,
            totalAmount: (float) $term->total_amount,
            dailyAmount: (float) $term->daily_amount,
            dailyTax: (float) $term->daily_tax,
        );
    }
}
