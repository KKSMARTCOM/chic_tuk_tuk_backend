<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Application\Data\AdminCommissionData;
use App\Models\Commission;
use App\Shared\Http\ApiException;

/** Annuler une commission — ex-Admin\CommissionController::destroy(), qui la supprimait. */
final class CancelCommission
{
    public function __invoke(string $commissionId): AdminCommissionData
    {
        $commission = $this->cancelCommission($commissionId);

        return AdminCommissionData::fromModel($commission->load(['driver.user', 'booking']));
    }

    /**
     * Annule une commission : elle ne compte plus dans ce que l'agent doit, et reste
     * visible. Décidé le 2026-09-26 — le Blade la supprimait définitivement.
     */
    private function cancelCommission(string $commissionId): Commission
    {
        $commission = Commission::findOrFail($commissionId);

        if ($commission->status === 'cancelled') {
            throw new ApiException(409, 'COMMISSION_ALREADY_CANCELLED', 'Cette commission est déjà annulée.');
        }

        $commission->update(['status' => 'cancelled']);

        return $commission->refresh();
    }
}
