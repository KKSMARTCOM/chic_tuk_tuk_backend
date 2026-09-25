<?php

namespace App\Domains\Fleet\Application\Data;

use App\Models\Vehicle;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * Un véhicule sur la fiche de son propriétaire.
 *
 * ⚠️ `hasDriver` décide de tout l'écran : un véhicule dont un agent a un contrat actif
 * est en LECTURE SEULE — ni le véhicule ni son contrat ne se modifient, et
 * `OwnerService::update()` ignore d'ailleurs ce qu'on lui enverrait pour lui.
 * `driverName` peut être nul alors que `hasDriver` est vrai : le Blade affiche alors
 * « un agent ».
 */
final class AdminOwnerVehicleData extends BaseData
{
    public function __construct(
        public string $id,
        public string $vehicleNumber,
        #[LiteralTypeScriptType("'moto' | 'tricycle' | 'car'")]
        public string $vehicleType,
        public ?string $notes,
        public bool $isActive,
        public bool $hasDriver,
        public ?string $driverName,
        public ?AdminOwnerVehicleContractData $contract,
    ) {}

    public static function fromModel(Vehicle $vehicle): self
    {
        $driverContract = $vehicle->activeDriverContract;
        $contract = $vehicle->activeVehicleContract;

        return new self(
            id: $vehicle->id,
            vehicleNumber: $vehicle->vehicle_number,
            vehicleType: $vehicle->vehicle_type,
            notes: $vehicle->notes,
            isActive: (bool) $vehicle->is_active,
            hasDriver: $driverContract !== null,
            driverName: $driverContract?->driver?->user?->name,
            contract: $contract ? AdminOwnerVehicleContractData::fromModel($contract) : null,
        );
    }
}
