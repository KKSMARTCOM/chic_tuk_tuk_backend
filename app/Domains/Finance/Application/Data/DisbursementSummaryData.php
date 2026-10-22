<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;

/**
 * Les trois chiffres du versement (spec 2026-10-22-versement-proprietaire, §2) : ce que les
 * fiches validées doivent au propriétaire, ce qui lui a été versé, et ce qui reste dû.
 */
final class DisbursementSummaryData extends BaseData
{
    public function __construct(
        public float $netEarned,
        public float $disbursed,
        /** Brut chez l'admin : négatif quand une fiche a été annulée après des versements. */
        public float $outstanding,
    ) {}
}
