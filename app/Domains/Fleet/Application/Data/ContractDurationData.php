<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;

/**
 * Une durée de contrat proposée, le montant total qui lui correspond, et le montant
 * investi qui préremplit le contrat (2026-10-14).
 */
final class ContractDurationData extends BaseData
{
    public function __construct(
        public int $months,
        public float $totalAmount,
        public ?float $investedAmount = null,
    ) {}
}
