<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;

/** Un véhicule, réduit à son badge dans la liste des propriétaires. */
final class AdminOwnerVehicleBadgeData extends BaseData
{
    public function __construct(
        public string $id,
        public string $vehicleNumber,
    ) {}
}
