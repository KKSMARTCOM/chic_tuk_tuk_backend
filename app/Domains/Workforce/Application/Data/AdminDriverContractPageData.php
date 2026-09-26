<?php

namespace App\Domains\Workforce\Application\Data;

use App\Shared\Data\BaseData;

/** GET /admin/driver-contracts */
final class AdminDriverContractPageData extends BaseData
{
    public function __construct(
        /** @var array<int, AdminDriverContractListItemData> */
        public array $contracts,
    ) {}
}
