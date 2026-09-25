<?php

namespace App\Domains\Workforce\Application\Data;

use App\Shared\Data\BaseData;

/** Les quatre compteurs en tête de la liste des agents — voir AdminDriverPageData. */
final class AdminDriverStatsData extends BaseData
{
    public function __construct(
        public int $total,
        public int $active,
        public int $inactive,
        public int $available,
    ) {}
}
