<?php

namespace App\Domains\Identity\Application\Data;

use App\Shared\Data\BaseData;

/** Une famille de permissions, dans l'ordre du menu. */
final class AdminPermissionGroupData extends BaseData
{
    public function __construct(
        public string $label,
        /** @var array<int, AdminPermissionData> */
        public array $permissions,
    ) {}
}
