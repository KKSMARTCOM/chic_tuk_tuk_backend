<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;

/** Le reste à recouvrer d'avant la mise en service, saisi sur la première fiche d'un contrat. */
final class RemunerationStatementOpeningData extends BaseData
{
    public function __construct(
        public float $internet,
        public float $spotify,
        public float $manager,
    ) {}
}
