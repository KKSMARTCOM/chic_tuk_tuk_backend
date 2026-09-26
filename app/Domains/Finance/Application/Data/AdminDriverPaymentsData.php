<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;

/**
 * GET /admin/drivers/{driver}/payments — ex-`pages.admin.payments.driver-details` : le
 * résumé, tous les paiements de l'agent, et ses commissions encore dues.
 */
final class AdminDriverPaymentsData extends BaseData
{
    public function __construct(
        public AdminDriverPaymentSummaryData $summary,
        /** @var array<int, AdminPaymentData> */
        public array $payments,
        /** @var array<int, AdminCommissionData> */
        public array $commissions,
    ) {}
}
