<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;

/** Le résultat d'une génération. `skipped` : les jours payés entre l'aperçu et la confirmation. */
final class ContractPaymentGenerationData extends BaseData
{
    public function __construct(
        public int $created,
        public float $totalNet,
        /** @var string[] */
        public array $skipped,
    ) {}
}
