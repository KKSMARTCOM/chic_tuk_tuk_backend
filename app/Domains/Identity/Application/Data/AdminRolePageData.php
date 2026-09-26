<?php

namespace App\Domains\Identity\Application\Data;

use App\Shared\Data\BaseData;

/** GET /admin/roles — l'onglet « Rôles ». */
final class AdminRolePageData extends BaseData
{
    public function __construct(
        /** @var array<int, AdminRoleListItemData> */
        public array $roles,
    ) {}
}
