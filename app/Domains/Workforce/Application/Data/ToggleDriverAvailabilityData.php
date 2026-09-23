<?php

namespace App\Domains\Workforce\Application\Data;

use App\Shared\Data\BaseData;

/** Le corps de POST /admin/drivers/{driver}/toggle-availability. */
final class ToggleDriverAvailabilityData extends BaseData
{
    public function __construct(public bool $isAvailable) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return ['is_available' => ['required', 'boolean']];
    }
}
