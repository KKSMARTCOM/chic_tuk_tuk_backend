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
        /** @var array<int, array{id: string, vehicle_number: string, vehicle_type: ?string, color: ?string, contract_months: ?int, contract_start_date: ?string}> */
        public array $vehicles,
    ) {}

    public static function fromModel(User $owner): self
    {
        return new self(
            id: $owner->id,
            name: $owner->name,
            phone: $owner->phone,
            vehicles: $owner->vehicles->map(fn ($v) => [
                'id' => $v->id,
                'vehicle_number' => $v->vehicle_number,
                'vehicle_type' => $v->vehicle_type,
                'color' => $v->color,
                'contract_months' => $v->activeVehicleContract?->contract_months,
                'contract_start_date' => $v->activeVehicleContract?->start_date?->format('Y-m-d'),
            ])->values()->all(),
        );
    }
}
