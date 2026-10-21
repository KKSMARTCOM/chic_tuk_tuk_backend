<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Domains\Finance\Domain\ContractTargets;
use App\Domains\Fleet\Application\Actions\EndVehiclePauseBeforeAgentStart;
use App\Domains\Fleet\Application\Actions\TakeOverVehicle;
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
    public function __construct(
        private readonly EndVehiclePauseBeforeAgentStart $endVehiclePause,
        private readonly TakeOverVehicle $takeOver,
        private readonly SyncDriverAvailability $syncAvailability,
    ) {}

    public function __invoke(Driver $driver, array $data): void
    {
        $vehicle = Vehicle::findOrFail($data['renewal_vehicle_id']);

        // Vérifier que le véhicule appartient au propriétaire sélectionné
        if ($vehicle->owner_id !== $data['renewal_owner_id']) {
            throw new \Exception('Ce véhicule n\'appartient pas au propriétaire sélectionné.');
        }

        // Un renouvellement suppose un contrat qui a déjà roulé : jamais un contrat en
        // attente (spec 2026-10-09, §5.3).
        $vehicleContract = $vehicle->activeVehicleContract;

        if (! $vehicleContract) {
            throw new \Exception('Le véhicule sélectionné n\'a pas de contrat actif.');
        }

        // Les mois TRAVAILLÉS du contrat propriétaire (2026-10-12) : une immobilisation ne
        // raccourcit plus le contrat de l'agent suivant. Durée restante nulle sur un contrat
        // actif : le véhicule roule jusqu'aux cumuls, d'au moins 1 mois.
        $remainingMonths = ContractTargets::remainingMonths($vehicleContract);
        $minimum = max(1, $remainingMonths);

        $requestedMonths = (int) $data['renewal_contract_months'];
        if ($requestedMonths < $minimum) {
            throw new \Exception($remainingMonths > 0
                ? "La durée demandée ({$requestedMonths} mois) est inférieure au temps restant ({$remainingMonths} mois) sur ce contrat."
                : "La durée demandée ({$requestedMonths} mois) est inférieure à 1 mois.");
        }

        // Validation règles métier (1 véhicule = 1 agent)
        VehicleAssignmentRules::assertAssignable($vehicle);
        VehicleAssignmentRules::assertAccountOpen($driver);

        // Clôturer la pause active du véhicule si existante, la veille de l'arrivée
        ($this->endVehiclePause)($vehicle, $data['renewal_start_date']);

        // Mettre le status du véhicule à actif
        $vehicle->update(['is_active' => true]);

        $elsewhere = $driver->activeInternalAssignment;
        if ($elsewhere && $elsewhere->vehicle_contract_id !== $vehicleContract->id) {
            throw new \Exception('Cet agent est affecté en interne à un autre véhicule : terminez d\'abord cette affectation.');
        }

        // L'affectation interne se termine la veille, un contrat en attente commence (2026-10-09).
        ($this->takeOver)($vehicleContract, $data['renewal_start_date'], $driver);

        DriverContract::create([
            'driver_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'vehicle_contract_id' => $vehicleContract->id,
            'start_date' => $data['renewal_start_date'],
            'contract_months' => $requestedMonths,
            'status' => 'active',
        ]);

        ($this->syncAvailability)($driver);
    }
}
