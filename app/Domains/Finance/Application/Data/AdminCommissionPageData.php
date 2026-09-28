<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;
use App\Shared\Data\PaginationData;

/** GET /admin/commissions — une page de la liste, et les compteurs de toutes. */
final class AdminCommissionPageData extends BaseData
{
    public function __construct(
        /** @var array<int, AdminCommissionData> */
        public array $commissions,
        public PaginationData $pagination,
        public AdminCommissionStatsData $stats,
    ) {}
}
