<?php

namespace App\Domains\Workforce\Application\Data;

use App\Models\User;
use App\Shared\Data\BaseData;

/**
 * Un propriétaire proposé au sélecteur « Nouveau contrat » de la création/édition
 * d'agent — ex-Admin\DriverController::create(), bloc `$owners`/`$ownerVehicles`.
 *
 * Seuls les véhicules ACTIFS et SANS contrat agent actif sont proposés : un véhicule déjà
 * confié à un agent ne peut pas en recevoir un second.
 */
final class AdminOwnerOptionData extends BaseData
{
    public function __construct(
        public string $id,
        public ?string $name,
        public ?string $phone,
        /** @var array<int, AdminOwnerVehicleOptionData> */
        public array $vehicles,
    ) {}

    public static function fromModel(User $owner): self
    {
        return new self(
            id: $owner->id,
            name: $owner->name,
            phone: $owner->phone,
            vehicles: $owner->vehicles->map(fn ($v) => new AdminOwnerVehicleOptionData(
                id: $v->id,
                vehicleNumber: $v->vehicle_number,
                vehicleType: $v->vehicle_type,
                color: $v->color,
                contractMonths: $v->activeVehicleContract?->contract_months,
                contractStartDate: $v->activeVehicleContract?->start_date?->format('Y-m-d'),
            ))->values()->all(),
        );
    }
}
