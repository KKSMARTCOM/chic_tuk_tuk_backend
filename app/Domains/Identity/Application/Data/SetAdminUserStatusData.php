<?php

namespace App\Domains\Identity\Application\Data;

use App\Shared\Data\BaseData;

/** Le statut d'un compte administrateur, POSÉ et non basculé — comme pour les propriétaires. */
final class SetAdminUserStatusData extends BaseData
{
    public function __construct(
        public bool $isActive,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return ['is_active' => ['required', 'boolean']];
    }
}
