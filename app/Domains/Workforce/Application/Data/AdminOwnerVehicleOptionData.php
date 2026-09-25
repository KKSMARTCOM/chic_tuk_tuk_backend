<?php

namespace App\Domains\Workforce\Application\Data;

use App\Shared\Data\BaseData;

/** Un véhicule proposé au sélecteur « Nouveau contrat » — voir AdminOwnerOptionData. */
final class AdminOwnerVehicleOptionData extends BaseData
{
    public function __construct(
        public string $id,
        public string $vehicleNumber,
        public ?string $vehicleType,
        public ?string $color,
        /** Repris du contrat propriétaire-véhicule ACTIF, pour pré-remplir le contrat agent. */
        public ?int $contractMonths,
        public ?string $contractStartDate,
    ) {}
}
