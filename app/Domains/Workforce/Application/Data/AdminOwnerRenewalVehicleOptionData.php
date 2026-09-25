<?php

namespace App\Domains\Workforce\Application\Data;

use App\Shared\Data\BaseData;

/** Un véhicule proposé au sélecteur « Reconduire un contrat » — voir AdminOwnerRenewalOptionData. */
final class AdminOwnerRenewalVehicleOptionData extends BaseData
{
    public function __construct(
        public string $id,
        public string $vehicleNumber,
        public ?string $vehicleType,
        public ?string $color,
        public int $totalMonths,
        public int $monthsUsed,
        /** Ne peut pas être dépassé : c'est le temps qu'il reste sur le contrat propriétaire. */
        public int $remainingMonths,
        public string $suggestedStartDate,
        public string $vehicleContractId,
    ) {}
}
