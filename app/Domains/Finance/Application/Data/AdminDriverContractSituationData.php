<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;

/**
 * Les paiements journaliers du contrat agent : validés, en attente, et parmi eux ceux en
 * RETARD — d'un jour déjà passé. Le jour même n'est pas en retard.
 */
final class AdminDriverContractSituationData extends BaseData
{
    public function __construct(
        public ?string $vehicleNumber,
        public string $startDate,
        public bool $isActive,
        public int $validatedCount,
        public float $validatedAmount,
        public int $pendingCount,
        public float $pendingAmount,
        public int $lateCount,
        public float $lateAmount,
        /** La plus récente date d'encaissement d'un paiement validé ; `null` si aucune n'est connue. */
        public ?string $lastCollectedOn,
    ) {}
}
