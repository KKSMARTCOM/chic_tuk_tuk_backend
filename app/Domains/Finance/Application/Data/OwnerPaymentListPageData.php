<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;
use App\Shared\Data\PaginationData;

/** GET /owner/vehicles/{id}/payment-list — une page, et les totaux de tout le filtre. */
final class OwnerPaymentListPageData extends BaseData
{
    public function __construct(
        /** @var array<int, OwnerPaymentListItemData> */
        public array $payments,
        public OwnerPaymentTotalsData $totals,
        public PaginationData $pagination,
    ) {}
}
