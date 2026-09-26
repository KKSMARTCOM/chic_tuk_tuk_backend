<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;

/** GET /admin/payments/{id} — ex-`pages.admin.payments.show`. */
final class AdminPaymentDetailData extends BaseData
{
    public function __construct(
        public AdminPaymentData $payment,
        public AdminDriverPaymentSummaryData $driverSummary,
    ) {}
}
