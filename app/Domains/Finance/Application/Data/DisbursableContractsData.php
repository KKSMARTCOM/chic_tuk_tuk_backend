<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;

final class DisbursableContractsData extends BaseData
{
    public function __construct(
        /** @var DisbursableContractData[] */
        public array $contracts,
    ) {}
}
