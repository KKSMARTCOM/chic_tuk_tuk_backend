<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;

/** Les trois cadres et la vue Versements de l'onglet Paiements ; `null` tant que les fiches sont masquées. */
final class OwnerDisbursementsData extends BaseData
{
    public function __construct(
        public ?DisbursementSummaryData $summary,
        /** @var OwnerDisbursementData[]|null */
        public ?array $disbursements,
    ) {}
}
