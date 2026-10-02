<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;
use App\Shared\Data\PaginationData;

/** GET /admin/commissions — une page de la liste, les compteurs de toutes, et les agents du filtre. */
final class AdminCommissionPageData extends BaseData
{
    public function __construct(
        /** @var array<int, AdminCommissionData> */
        public array $commissions,
        public PaginationData $pagination,
        public AdminCommissionStatsData $stats,
        /** @var array<int, AdminPaymentDriverOptionData> */
        public array $drivers = [],
    ) {}
}
