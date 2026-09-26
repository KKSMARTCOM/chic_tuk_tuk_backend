<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Application\Data\AdminCommissionData;
use App\Domains\Finance\Application\Data\AdminCommissionPageData;
use App\Domains\Finance\Application\Data\AdminCommissionStatsData;
use App\Models\Commission;
use App\Services\CommissionService;

/** La liste des commissions — ex-Admin\CommissionController::index(). */
final class ListCommissions
{
    public function __construct(private readonly CommissionService $commissionService) {}

    /** @param  array{driver_id?: ?string, search?: ?string}  $filters */
    public function __invoke(array $filters = []): AdminCommissionPageData
    {
        $stats = $this->commissionService->getCommissionStats();

        return new AdminCommissionPageData(
            commissions: $this->commissionService->getAllCommissions($filters)
                ->map(fn (Commission $commission) => AdminCommissionData::fromModel($commission))
                ->all(),
            stats: new AdminCommissionStatsData(
                totalRevenue: (float) $stats['total_revenue'],
                totalCount: (int) $stats['total_count'],
            ),
        );
    }
}
