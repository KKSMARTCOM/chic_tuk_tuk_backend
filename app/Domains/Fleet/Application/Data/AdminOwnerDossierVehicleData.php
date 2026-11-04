<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * Un véhicule dans le dossier propriétaire. `state` reprend les états de l'espace
 * propriétaire (`OwnerVehicleSummaryData::ownerState`), plus `no_contract` et `inactive`.
 */
final class AdminOwnerDossierVehicleData extends BaseData
{
    public function __construct(
        public string $id,
        public string $vehicleNumber,
        #[LiteralTypeScriptType("'moto' | 'tricycle' | 'car'")]
        public string $vehicleType,
        #[LiteralTypeScriptType("'active' | 'paused' | 'immobilized' | 'pending' | 'completed' | 'no_contract' | 'inactive'")]
        public string $state,
        /** Le début de la pause en cours : une pause en cours n'a pas de date de fin. */
        public ?string $pauseSince,
        public ?string $pauseReasonLabel,
        /** L'agent actuel ; `id` est celui de l'AGENT. */
        public ?AdminVehiclePersonData $driver,
        public ?AdminOwnerDossierContractData $contract,
        /** @var array<int, AdminOwnerDossierPastContractData> */
        public array $pastContracts,
    ) {}
}
