<?php

namespace App\Domains\Identity\Application\Data;

use App\Shared\Data\BaseData;

/** Les trois compteurs de l'écran des administrateurs, filtres non appliqués. */
final class AdminUserStatsData extends BaseData
{
    public function __construct(
        public int $total,
        public int $active,
        public int $inactive,
    ) {}
}
