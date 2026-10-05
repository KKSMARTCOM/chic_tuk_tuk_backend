<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Domains\Workforce\Domain\VehicleAssignmentRules;
use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\Vehicle;

/**
 * Renouvelle le contrat d'un agent — ex-`DriverService::createRenewalContract()`, déplacé
 * sans changement le 2026-09-27 : la création et la modification d'un agent le partagent.
 */
final class CreateRenewalContract
{
    public function __invoke(Driver $driver, array $data): void
    {
        $vehicle = Vehicle::findOrFail($data['renewal_vehicle_id']);

        // Vérifier que le véhicule appartient au propriétaire sélectionné
        if ($vehicle->owner_id !== $data['renewal_owner_id']) {
            throw new \Exception('Ce véhicule n\'appartient pas au propriétaire sélectionné.');
        }

        $vehicleContract = $vehicle->activeVehicleContract;

        if (! $vehicleContract) {
            throw new \Exception('Le véhicule sélectionné n\'a pas de contrat actif.');
        }

        // Calculer les mois déjà utilisés sur ce contrat proprio-véhicule
        $monthsUsed = DriverContract::where('vehicle_id', $vehicle->id)
            ->where('status', 'ended')
            ->get(['start_date', 'end_date'])
            ->sum(function ($contract) {
                return ($contract->end_date->year * 12 + $contract->end_date->month)
                    - ($contract->start_date->year * 12 + $contract->start_date->month)
                    + 1;
            });

        $remainingMonths = max(0, $vehicleContract->contract_months - $monthsUsed);

        if ($remainingMonths <= 0) {
            throw new \Exception('Ce contrat propriétaire ne dispose plus de temps restant pour une reconduction.');
        }

        // La durée demandée ne peut pas être inférieure à 1 mois et ne peut pas dépasser le temps restant sur le contrat
        $requestedMonths = (int) $data['renewal_contract_months'];
        if ($requestedMonths < 1) {
            throw new \Exception("La durée demandée ({$requestedMonths} mois) est inférieure à 1 mois.");
        }

        if ($requestedMonths < $remainingMonths) {
            throw new \Exception(
                "La durée demandée ({$requestedMonths} mois) est inférieure au temps restant ({$remainingMonths} mois) sur ce contrat."
            );
        }

        // Validation règles métier (1 véhicule = 1 agent)
        VehicleAssignmentRules::assertAssignable($vehicle);

        // Clôturer la pause active du véhicule si existante
        $vehicle->activePause?->update(['end_date' => $data['renewal_start_date']]);

        // Mettre le status du véhicule à actif
        $vehicle->update(['is_active' => true]);

        DriverContract::create([
            'driver_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'vehicle_contract_id' => $vehicleContract->id,
            'start_date' => $data['renewal_start_date'],
            'contract_months' => $requestedMonths,
            'status' => 'active',
        ]);
    }
}
