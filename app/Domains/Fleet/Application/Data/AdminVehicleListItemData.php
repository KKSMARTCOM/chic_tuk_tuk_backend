<?php

namespace App\Domains\Fleet\Application\Data;

use App\Models\Vehicle;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * Une ligne de la liste des véhicules — ex-Admin\VehicleController::index().
 *
 * `isOnPause` suit la définition du modèle : une pause SANS date de fin. `activePauseId`
 * permet de la terminer depuis la liste, comme le Blade.
 */
final class AdminVehicleListItemData extends BaseData
{
    public function __construct(
        public string $id,
        public string $vehicleNumber,
        #[LiteralTypeScriptType("'moto' | 'tricycle' | 'car'")]
        public string $vehicleType,
        public ?string $notes,
        public bool $isActive,
        public bool $isOnPause,
        public ?string $activePauseId,
        public ?AdminVehiclePersonData $owner,
        public ?AdminVehiclePersonData $driver,
        public ?AdminVehicleContractProgressData $contract,
        public string $createdAt,
    ) {}

    public static function fromModel(Vehicle $vehicle): self
    {
        $driverContract = $vehicle->activeDriverContract;
        $driver = $driverContract?->driver;

        return new self(
            id: $vehicle->id,
            vehicleNumber: $vehicle->vehicle_number,
            vehicleType: $vehicle->vehicle_type,
            notes: $vehicle->notes,
            isActive: (bool) $vehicle->is_active,
            isOnPause: $vehicle->activePause !== null,
            activePauseId: $vehicle->activePause?->id,
            owner: $vehicle->owner
                ? new AdminVehiclePersonData($vehicle->owner->id, $vehicle->owner->name, $vehicle->owner->phone)
                : null,
            driver: $driver
                ? new AdminVehiclePersonData($driver->id, $driver->user?->name, $driver->user?->phone)
                : null,
            contract: $vehicle->activeVehicleContract
                ? AdminVehicleContractProgressData::fromModel($vehicle->activeVehicleContract)
                : null,
            createdAt: $vehicle->created_at->toIso8601String(),
        );
    }
}
