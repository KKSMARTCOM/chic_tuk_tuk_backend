<?php

namespace App\Domains\Fleet\Application\Data;

use App\Models\User;
use App\Models\Vehicle;
use App\Shared\Data\BaseData;

/**
 * GET /admin/owners/{owner} — un propriétaire et ses véhicules, pour l'écran d'édition.
 *
 * Le Blade n'a pas de fiche distincte : l'écran d'édition en tient lieu.
 */
final class AdminOwnerDetailData extends BaseData
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $email,
        public string $phone,
        public ?string $adresse,
        public bool $isActive,
        /** @var array<int, AdminOwnerVehicleData> */
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
                ->sortBy('vehicle_number')
                ->map(fn (Vehicle $vehicle) => AdminOwnerVehicleData::fromModel($vehicle))
                ->values()
                ->all(),
            createdAt: $owner->created_at->toIso8601String(),
        );
    }
}
