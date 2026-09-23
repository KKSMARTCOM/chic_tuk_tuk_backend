<?php

namespace App\Domains\Workforce\Application\Data;

use App\Shared\Data\BaseData;

/** Le corps de POST /admin/drivers/{driver}/toggle-status. */
final class ToggleDriverStatusData extends BaseData
{
    public function __construct(public bool $isActive) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return ['is_active' => ['required', 'boolean']];
    }
}
