<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;
use App\Shared\Data\PaginationData;

/** GET /admin/owners — la liste des propriétaires et ses compteurs. */
final class AdminOwnerPageData extends BaseData
{
    public function __construct(
        /** @var array<int, AdminOwnerListItemData> */
        public array $owners,
        public PaginationData $pagination,
        public AdminOwnerStatsData $stats,
    ) {}
}
