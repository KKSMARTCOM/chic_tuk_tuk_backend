<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Domains\Fleet\Application\Actions\EndVehiclePauseBeforeAgentStart;
use App\Domains\Workforce\Application\Data\UpdateDriverContractData;
use App\Domains\Workforce\Domain\DriverContractRules;
use App\Domains\Workforce\Domain\VehicleAssignmentRules;
use App\Models\DriverContract;
use App\Models\Vehicle;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/** Modifier un contrat agent — ex-Admin\DriverContractController::update(). */
final class UpdateDriverContract
{
    public function __construct(private readonly EndVehiclePauseBeforeAgentStart $endVehiclePause) {}

    public function __invoke(DriverContract $contract, UpdateDriverContractData $data): DriverContract
    {
        $vehicle = $data->vehicleId ? Vehicle::findOrFail($data->vehicleId) : $contract->vehicle;

        return $this->updateContract($contract, [
            'start_date' => $data->startDate,
            'contract_months' => $data->contractMonths,
        ], $vehicle);
    }

    /**
     * Modifie la date de début, la durée et, au besoin, le véhicule d'un contrat agent.
     *
     * Corrigé le 2026-09-26 :
     *  - la règle « modifiable seulement sans pause agent ni paiement » n'était portée que
     *    par la vue Blade du dossier ; le service l'impose ;
     *  - changer de véhicule gardait le `vehicle_contract_id` de l'ancien, et les
     *    paiements suivants partaient sur le mauvais contrat véhicule. Le contrat suit
     *    désormais le contrat véhicule actif du nouveau véhicule, qui doit en avoir un.
     */
    private function updateContract(DriverContract $contract, array $data, Vehicle $vehicle): DriverContract
    {
        return DB::transaction(function () use ($contract, $data, $vehicle) {
            if (DriverContractRules::hasHistory($contract)) {
                throw new ApiException(
                    409,
                    'DRIVER_CONTRACT_LOCKED',
                    'Ce contrat ne peut plus être modifié directement car il a des pauses ou paiements liés. '
                        .'Pour changer de véhicule, terminez ce contrat et créez-en un nouveau.'
                );
            }

            $updateData = [
                'start_date' => $data['start_date'],
                'contract_months' => $data['contract_months'],
            ];

            if ($vehicle->id !== $contract->vehicle_id) {
                VehicleAssignmentRules::assertAssignable($vehicle, $contract->driver_id, $contract->id);

                $vehicleContract = $vehicle->activeVehicleContract;
                if (! $vehicleContract) {
                    throw new ApiException(
                        409,
                        'VEHICLE_WITHOUT_CONTRACT',
                        "Le véhicule {$vehicle->vehicle_number} n'a pas de contrat propriétaire actif."
                    );
                }

                // La pause du véhicule d'arrivée se ferme la veille du début (2026-10-06).
                ($this->endVehiclePause)($vehicle, $data['start_date']);

                $updateData['vehicle_id'] = $vehicle->id;
                $updateData['vehicle_contract_id'] = $vehicleContract->id;
            }

            $contract->update($updateData);

            return $contract->refresh();
        });
    }
}
