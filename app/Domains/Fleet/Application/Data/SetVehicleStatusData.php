<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;

/** Le statut d'un véhicule, POSÉ et non basculé — voir `SetOwnerStatusData`. */
final class SetVehicleStatusData extends BaseData
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
