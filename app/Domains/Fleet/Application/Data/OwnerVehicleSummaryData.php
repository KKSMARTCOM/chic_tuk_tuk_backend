<?php

namespace App\Domains\Fleet\Application\Data;

use App\Domains\Fleet\Domain\VehicleOwnerState;
use App\Models\Vehicle;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

final class OwnerVehicleSummaryData extends BaseData
{
    public function __construct(
        public string $id,
        public string $vehicleNumber,
        public ?string $vehicleType,
        /** Actif, En pause, ou Immobilisé — en attente d'un nouvel agent (spec §6.1). */
        #[LiteralTypeScriptType('"active" | "paused" | "immobilized"')]
        public string $state,
        /** Le motif de la pause en cours, affiché à côté de l'état. */
        public ?string $pauseReasonLabel,
        public ?OwnerContractSummaryData $contract,
    ) {}

    /** Attend un véhicule ayant chargé `activeVehicleContract` et `activePause`. */
    public static function fromModel(Vehicle $vehicle): self
    {
        $contract = $vehicle->activeVehicleContract;

        return new self(
            id: $vehicle->id,
            vehicleNumber: $vehicle->vehicle_number,
            vehicleType: $vehicle->vehicle_type,
            state: VehicleOwnerState::of($vehicle->activePause),
            pauseReasonLabel: $vehicle->activePause?->reason_label,
            // Le véhicule peut n'avoir aucun contrat actif : c'est un cas réel, que le
            // Blade traite déjà par « Aucun contrat actif pour ce véhicule ».
            contract: $contract ? OwnerContractSummaryData::fromModel($contract) : null,
        );
    }
}
