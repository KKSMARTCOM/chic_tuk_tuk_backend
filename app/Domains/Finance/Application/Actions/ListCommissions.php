<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Application\Data\AdminCommissionData;
use App\Domains\Finance\Application\Data\AdminCommissionPageData;
use App\Domains\Finance\Application\Data\AdminCommissionStatsData;
use App\Models\Commission;

/** La liste des commissions — ex-Admin\CommissionController::index(). */
final class ListCommissions
{
    /** @param  array{driver_id?: ?string, search?: ?string}  $filters */
    public function __invoke(array $filters = []): AdminCommissionPageData
    {
        $stats = $this->getCommissionStats();

        return new AdminCommissionPageData(
            commissions: $this->getAllCommissions($filters)
                ->map(fn (Commission $commission) => AdminCommissionData::fromModel($commission))
                ->all(),
            stats: new AdminCommissionStatsData(
                totalRevenue: (float) $stats['total_revenue'],
                totalCount: (int) $stats['total_count'],
            ),
        );
    }

    private function getAllCommissions($filters = [])
    {
        $query = Commission::query()
            ->with(['driver.user', 'booking'])
            ->latest();

        if (isset($filters['driver_id']) && ! empty($filters['driver_id'])) {
            $query->where('driver_id', $filters['driver_id']);
        }

        // Groupé (2026-09-26) : sans parenthèses, le `orWhereHas` échappait au filtre
        // d'agent, et un numéro de course d'un autre agent remontait quand même.
        if (isset($filters['search']) && ! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->whereHas('driver.user', fn ($u) => $u->where('name', 'ilike', '%'.$search.'%'))
                    ->orWhereHas('booking', fn ($b) => $b->where('booking_number', 'ilike', '%'.$search.'%'));
            });
        }

        return $query->latest()->get();
    }

    private function getCommissionStats()
    {
        $totalRevenue = Commission::where('status', 'active')->sum('amount');
        $totalCommissionsCount = Commission::count();

        return [
            'total_revenue' => $totalRevenue,
            'total_count' => $totalCommissionsCount,
        ];
    }
}
