<?php

namespace App\Domains\Workforce\Application\Data;

use App\Shared\Data\BaseData;

/**
 * GET /admin/drivers — la liste des agents et ses compteurs.
 *
 * Longtemps un tableau PHP brut : une réponse qui n'est pas une classe Data échappe à la
 * génération des types du front, et donc au test qui en surveille la fraîcheur.
 */
final class AdminDriverPageData extends BaseData
{
    public function __construct(
        /** @var array<int, AdminDriverListItemData> */
        public array $drivers,
        public AdminDriverStatsData $stats,
    ) {}
}
