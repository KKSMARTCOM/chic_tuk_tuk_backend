<?php

namespace App\Domains\Identity\Application\Data;

use App\Shared\Data\BaseData;

/**
 * GET /admin/permissions — le catalogue de référence, groupé par famille. Il sert
 * l'onglet « Permissions », en lecture seule, et les cases de la modale d'un rôle.
 */
final class AdminPermissionCatalogData extends BaseData
{
    public function __construct(
        /** @var array<int, AdminPermissionGroupData> */
        public array $groups,
    ) {}
}
