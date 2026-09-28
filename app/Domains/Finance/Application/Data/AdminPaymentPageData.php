<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;
use App\Shared\Data\PaginationData;

/** GET /admin/payments — une page de la liste filtrée, les compteurs et les agents du filtre. */
final class AdminPaymentPageData extends BaseData
{
    public function __construct(
        /** @var array<int, AdminPaymentData> */
        public array $payments,
        public PaginationData $pagination,
        public AdminPaymentStatsData $stats,
        /** @var array<int, AdminPaymentDriverOptionData> */
        public array $drivers,
    ) {}
}
