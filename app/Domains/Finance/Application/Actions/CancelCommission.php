<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Application\Data\AdminCommissionData;
use App\Services\CommissionService;

/** Annuler une commission — ex-Admin\CommissionController::destroy(), qui la supprimait. */
final class CancelCommission
{
    public function __construct(private readonly CommissionService $commissionService) {}

    public function __invoke(string $commissionId): AdminCommissionData
    {
        $commission = $this->commissionService->cancelCommission($commissionId);

        return AdminCommissionData::fromModel($commission->load(['driver.user', 'booking']));
    }
}
