<?php

namespace App\Domains\Fleet\Application\Data;

use App\Models\User;
use App\Models\Vehicle;
use App\Shared\Data\BaseData;

/** Une ligne de la liste des propriétaires — ex-Admin\OwnerController::index(). */
final class AdminOwnerListItemData extends BaseData
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $email,
        public string $phone,
        public ?string $adresse,
        public bool $isActive,
        /** @var array<int, AdminOwnerVehicleBadgeData> */
        public array $vehicles,
        public string $createdAt,
    ) {}

    public static function fromModel(User $owner): self
    {
        return new self(
            id: $owner->id,
            name: $owner->name,
            email: $owner->email,
            phone: $owner->phone,
            adresse: $owner->adresse,
            isActive: (bool) $owner->is_active,
            vehicles: $owner->vehicles
                ->map(fn (Vehicle $vehicle) => new AdminOwnerVehicleBadgeData($vehicle->id, $vehicle->vehicle_number))
                ->all(),
            createdAt: $owner->created_at->toIso8601String(),
        );
    }
}
