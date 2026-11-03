<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;

/**
 * Les compteurs en tête de la liste des véhicules. `immobilized` : les véhicules qui attendent un
 * nouvel agent, retirés de `paused` depuis le 2026-11-03.
 */
final class AdminVehicleStatsData extends BaseData
{
    public function __construct(
        public int $total,
        public int $active,
        public int $paused,
        public int $immobilized,
        public int $withoutContract,
    ) {}
}
