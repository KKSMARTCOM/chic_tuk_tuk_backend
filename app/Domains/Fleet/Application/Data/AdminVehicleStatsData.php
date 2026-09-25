<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;

/** Les quatre compteurs en tête de la liste des véhicules. */
final class AdminVehicleStatsData extends BaseData
{
    public function __construct(
        public int $total,
        public int $active,
        public int $paused,
        public int $withoutContract,
    ) {}
}
