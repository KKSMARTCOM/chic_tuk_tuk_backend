<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;

/** Une durée de contrat proposée, et le montant total qui lui correspond. */
final class ContractDurationData extends BaseData
{
    public function __construct(
        public int $months,
        public float $totalAmount,
    ) {}
}
