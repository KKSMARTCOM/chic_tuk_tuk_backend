<?php

namespace App\Domains\Fleet\Application\Data;

use App\Domains\Fleet\Domain\VehicleOwnerState;
use App\Models\Vehicle;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

final class OwnerVehicleDetailData extends BaseData
{
    public function __construct(
        public string $id,
        public string $vehicleNumber,
        public ?string $vehicleType,
        #[LiteralTypeScriptType('"active" | "paused" | "immobilized" | "pending" | "completed"')]
        public string $state,
        public ?ActivePauseData $activePause,
        public ?OwnerContractDetailData $contract,
    ) {}

    /** Attend un véhicule ayant chargé `activeVehicleContract`, `liveVehicleContract`, `settledVehicleContract` et `activePause`. */
    public static function fromModel(Vehicle $vehicle): self
    {
        $settled = $vehicle->liveVehicleContract === null ? $vehicle->settledVehicleContract : null;
        $contract = $vehicle->activeVehicleContract ?? $settled;
        // Soldé : l'agent qui continue roule pour l'agence, ses pauses ne sont pas celles du
        // propriétaire (spec 2026-10-12, §7.2).
        $pause = $settled ? null : $vehicle->activePause;

        return new self(
            id: $vehicle->id,
            vehicleNumber: $vehicle->vehicle_number,
            vehicleType: $vehicle->vehicle_type,
            // En attente de son premier agent : ni chiffres ni pause à montrer (2026-10-09).
            state: match (true) {
                $settled !== null => 'completed',
                $vehicle->liveVehicleContract?->status === 'pending' => 'pending',
                default => VehicleOwnerState::of($pause),
            },
            activePause: $pause ? ActivePauseData::fromModel($pause) : null,
            contract: $contract ? OwnerContractDetailData::fromModel($contract) : null,
        );
    }
}
