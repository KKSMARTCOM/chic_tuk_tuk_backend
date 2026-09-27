<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\UpdateVehicleContractData;
use App\Domains\Fleet\Domain\VehicleContractRules;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/** Modifier un contrat véhicule — ex-Admin\VehicleContractController::update(). */
final class UpdateVehicleContract
{
    public function __invoke(VehicleContract $contract, UpdateVehicleContractData $data): VehicleContract
    {
        return $this->updateContract($contract, $data->toServicePayload());
    }

    /**
     * Modifie un contrat.
     *
     * Corrigé le 2026-09-26 : la date de fin n'est plus touchée — le contrôleur Blade
     * lisait un `end_date` que sa validation ne laissait jamais passer, et chaque
     * enregistrement l'effaçait. Un contrat actif ne peut plus atterrir sur un véhicule
     * qui en a déjà un, ni sur un véhicule sans propriétaire.
     */
    private function updateContract(VehicleContract $contract, array $data): VehicleContract
    {
        return DB::transaction(function () use ($contract, $data) {
            if ($contract->vehicle && $contract->vehicle->activeDriverContract()->exists()) {
                throw new ApiException(
                    409,
                    'VEHICLE_CONTRACT_HAS_ACTIVE_DRIVER',
                    'Impossible de modifier ce contrat : le véhicule possède un agent actif.'
                );
            }

            $updateData = [
                'total_amount' => $data['total_amount'],
                'start_date' => $data['start_date'],
                'status' => $data['status'] ?? $contract->status,
                'notes' => $data['notes'] ?? null,
                'contract_months' => $data['contract_months'] ?? $contract->contract_months,
                'unlimited_internet' => $data['unlimited_internet'] ?? $contract->unlimited_internet,
                'spotify_premium' => $data['spotify_premium'] ?? $contract->spotify_premium,
                'manager_remuneration' => $data['manager_remuneration'] ?? $contract->manager_remuneration,
            ];

            $vehicle = $contract->vehicle;

            if (! empty($data['vehicle_id']) && $data['vehicle_id'] !== $contract->vehicle_id) {
                $vehicle = Vehicle::query()->lockForUpdate()->findOrFail($data['vehicle_id']);
                if ($vehicle->activeDriverContract()->exists()) {
                    throw new ApiException(
                        409,
                        'VEHICLE_CONTRACT_HAS_ACTIVE_DRIVER',
                        'Impossible de changer le véhicule : le véhicule sélectionné possède un agent actif.'
                    );
                }

                VehicleContractRules::assertHasOwner($vehicle);

                $updateData['vehicle_id'] = $vehicle->id;
                $updateData['owner_id'] = $vehicle->owner_id;
            }

            if ($updateData['status'] === 'active' && $vehicle) {
                VehicleContractRules::assertCanCarryAnActiveContract($vehicle, except: $contract);
            }

            $contract->update($updateData);

            return $contract->refresh();
        });
    }
}
