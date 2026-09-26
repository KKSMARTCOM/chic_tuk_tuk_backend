<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;

/** GET /admin/payments — la liste filtrée, les compteurs et les agents du filtre. */
final class AdminPaymentPageData extends BaseData
{
    public function __construct(
        /** @var array<int, AdminPaymentData> */
        public array $payments,
        public AdminPaymentStatsData $stats,
        /** @var array<int, AdminPaymentDriverOptionData> */
        public array $drivers,
    ) {}
}
