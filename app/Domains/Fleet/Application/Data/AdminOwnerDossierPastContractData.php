<?php

namespace App\Domains\Fleet\Application\Data;

use App\Domains\Fleet\Domain\Enums\VehicleContractStatus;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/** Un ancien contrat du propriétaire sur ce véhicule, une ligne avec son lien. */
final class AdminOwnerDossierPastContractData extends BaseData
{
    public function __construct(
        public string $id,
        #[TypeScriptType(VehicleContractStatus::class)]
        public string $status,
        public ?string $endDate,
    ) {}
}
