<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;

/**
 * Les sept cartes de `pages.admin.payments.index`, sur TOUS les paiements — comme au
 * Blade, pas sur la liste filtrée. Les quatre premières portent sur les commissions, les
 * trois dernières sur les paiements de contrat.
 */
final class AdminPaymentStatsData extends BaseData
{
    public function __construct(
        public float $totalPaid,
        public float $totalDue,
        public float $balanceDue,
        public float $paidThisMonth,
        public float $validatedPaymentsAmount,
        public int $validatedPaymentsCount,
        public float $pendingPaymentsAmount,
        public int $pendingPaymentsCount,
        public float $cancelledPaymentsAmount,
        public int $cancelledPaymentsCount,
    ) {}

    /** @param  array<string, mixed>  $stats  `PaymentService::getPaymentStats()` */
    public static function fromStats(array $stats): self
    {
        return new self(
            totalPaid: (float) $stats['total_paid'],
            totalDue: (float) $stats['total_due'],
            balanceDue: (float) $stats['balance_due'],
            paidThisMonth: (float) $stats['paid_this_month'],
            validatedPaymentsAmount: (float) $stats['validated_payments_amount'],
            validatedPaymentsCount: (int) $stats['validated_payments_count'],
            pendingPaymentsAmount: (float) $stats['pending_payments_amount'],
            pendingPaymentsCount: (int) $stats['pending_payments_count'],
            cancelledPaymentsAmount: (float) $stats['cancelled_payments_amount'],
            cancelledPaymentsCount: (int) $stats['cancelled_payments_count'],
        );
    }
}
