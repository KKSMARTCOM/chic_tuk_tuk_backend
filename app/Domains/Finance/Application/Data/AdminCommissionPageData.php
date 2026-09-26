<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;

/** GET /admin/commissions */
final class AdminCommissionPageData extends BaseData
{
    public function __construct(
        /** @var array<int, AdminCommissionData> */
        public array $commissions,
        public AdminCommissionStatsData $stats,
    ) {}
}
