<?php

namespace App\Domains\Fleet\Application\Data;

use App\Models\Vehicle;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * Un véhicule qu'on peut rattacher à un propriétaire : actif, sans contrat
 * propriétaire-véhicule en cours.
 *
 * `ownerName` non nul annonce un TRANSFERT : le véhicule appartient déjà à quelqu'un, et
 * l'API exigera `confirm_transfer` pour le lui retirer.
 */
final class AdminAvailableVehicleData extends BaseData
{
    public function __construct(
        public string $id,
        public string $vehicleNumber,
        #[LiteralTypeScriptType("'moto' | 'tricycle' | 'car'")]
        public string $vehicleType,
        public ?string $ownerId,
        public ?string $ownerName,
    ) {}

    public static function fromModel(Vehicle $vehicle): self
    {
        return new self(
            id: $vehicle->id,
            vehicleNumber: $vehicle->vehicle_number,
            vehicleType: $vehicle->vehicle_type,
            ownerId: $vehicle->owner_id,
            ownerName: $vehicle->owner?->name,
        );
    }
}
