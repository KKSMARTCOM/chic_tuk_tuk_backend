<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;

/** Les trois compteurs en tête de la liste des propriétaires. */
final class AdminOwnerStatsData extends BaseData
{
    public function __construct(
        public int $total,
        public int $active,
        public int $inactive,
    ) {}
}
