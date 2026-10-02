<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;

/** GET /admin/payments/{id} — le paiement, et la situation de son agent (2026-10-02). */
final class AdminPaymentDetailData extends BaseData
{
    public function __construct(
        public AdminPaymentData $payment,
        public AdminDriverSituationData $driverSituation,
    ) {}
}
