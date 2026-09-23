<?php

namespace App\Domains\Workforce\Application\Data;

use App\Models\User;
use App\Shared\Data\BaseData;

/**
 * Un propriétaire proposé au sélecteur « Reconduire un contrat » de la
 * création/édition d'agent — ex-Admin\DriverController::create(), bloc
 * `$ownersForRenewal`/`$ownerVehiclesForRenewal`.
 *
 * Seuls les véhicules ayant eu un agent dont le contrat est TERMINÉ, dont le contrat
 * PROPRIO-VÉHICULE est encore actif, et qui n'ont PAS d'agent actif aujourd'hui, sont
 * proposés — un véhicule qu'on peut reconduire sur le temps qu'il reste à son contrat.
 */
final class AdminOwnerRenewalOptionData extends BaseData
{
    public function __construct(
        public string $id,
        public ?string $name,
        public ?string $phone,
        /** @var array<int, array{id: string, vehicle_number: string, vehicle_type: ?string, color: ?string, total_months: int, months_used: int, remaining_months: int, suggested_start_date: string, vehicle_contract_id: string}> */
        public array $vehicles,
    ) {}

    public static function fromModel(User $owner): self
    {
        return new self(
            id: $owner->id,
            name: $owner->name,
            phone: $owner->phone,
            vehicles: $owner->vehicles
                ->map(function ($v) {
                    $vehicleContract = $v->activeVehicleContract;
                    if (! $vehicleContract) {
                        return null;
                    }

                    // Mois déjà utilisés = somme des contrats agents TERMINÉS sur ce véhicule.
                    $monthsUsed = $v->driverContracts
                        ->where('status', 'ended')
                        ->sum(fn ($contract) => ($contract->end_date->year * 12 + $contract->end_date->month)
                            - ($contract->start_date->year * 12 + $contract->start_date->month)
                            + 1);

                    $totalMonths = $vehicleContract->contract_months;
                    $remainingMonths = max(0, $totalMonths - $monthsUsed);

                    // Date de début suggérée = lendemain de la fin du DERNIER contrat agent.
                    $lastDriverContract = $v->driverContracts->first();
                    $suggestedStartDate = $lastDriverContract?->end_date
                        ? $lastDriverContract->end_date->addDay()->format('Y-m-d')
                        : now()->toDateString();

                    return [
                        'id' => $v->id,
                        'vehicle_number' => $v->vehicle_number,
                        'vehicle_type' => $v->vehicle_type,
                        'color' => $v->color,
                        'total_months' => $totalMonths,
                        'months_used' => $monthsUsed,
                        'remaining_months' => $remainingMonths,
                        'suggested_start_date' => $suggestedStartDate,
                        'vehicle_contract_id' => $vehicleContract->id,
                    ];
                })
                ->filter()
                ->values()
                ->all(),
        );
    }
}
