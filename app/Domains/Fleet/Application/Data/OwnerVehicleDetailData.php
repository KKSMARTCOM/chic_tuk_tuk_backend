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
        #[LiteralTypeScriptType('"active" | "paused" | "immobilized"')]
        public string $state,
        public ?ActivePauseData $activePause,
        public ?OwnerContractDetailData $contract,
    ) {}

    /** Attend un véhicule ayant chargé `activeVehicleContract` et `activePause`. */
    public static function fromModel(Vehicle $vehicle): self
    {
        $pause = $vehicle->activePause;
        $contract = $vehicle->activeVehicleContract;

        return new self(
            id: $vehicle->id,
            vehicleNumber: $vehicle->vehicle_number,
            vehicleType: $vehicle->vehicle_type,
            state: VehicleOwnerState::of($pause),
            activePause: $pause ? ActivePauseData::fromModel($pause) : null,
            contract: $contract ? OwnerContractDetailData::fromModel($contract) : null,
        );
    }
}
