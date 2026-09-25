<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;

/**
 * GET /admin/vehicles — la liste, ses compteurs et les propriétaires du filtre.
 *
 * ⚠️ Comme au Blade, les compteurs portent sur la liste FILTRÉE : ils décrivent ce que
 * l'écran montre, pas la flotte entière.
 */
final class AdminVehiclePageData extends BaseData
{
    public function __construct(
        /** @var array<int, AdminVehicleListItemData> */
        public array $vehicles,
        public AdminVehicleStatsData $stats,
        /** @var array<int, AdminVehiclePersonData> */
        public array $owners,
    ) {}
}
