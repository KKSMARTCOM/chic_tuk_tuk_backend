<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;

/**
 * Les deux cartes du Blade : le total des commissions DUES, et le nombre de commissions,
 * annulées comprises. Comme au Blade, sur toutes les commissions, pas sur la liste filtrée.
 */
final class AdminCommissionStatsData extends BaseData
{
    public function __construct(
        public float $totalRevenue,
        public int $totalCount,
    ) {}
}
