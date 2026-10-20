<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\UpdateVehicleContractData;
use App\Domains\Fleet\Domain\ContractTerms;
use App\Domains\Fleet\Domain\VehicleContractRules;
use App\Models\Vehicle;
use App\Domains\Fleet\Domain\PreparationSteps;
use App\Models\VehicleContract;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Modifier un contrat véhicule — ex-Admin\VehicleContractController::update(). */
final class UpdateVehicleContract
{
    public function __construct(private readonly CheckContractTotal $checkTotal) {}

    public function __invoke(VehicleContract $contract, UpdateVehicleContractData $data): VehicleContract
    {
        // « Soldé » ne s'obtient que par l'action dédiée, aux cumuls atteints (spec 2026-10-12, §4.3).
        if ($data->status === 'completed' && $contract->status !== 'completed') {
            throw ValidationException::withMessages(['status' => 'Un contrat se solde par « Solder le contrat », une fois ses deux cumuls atteints.']);
        }
        // Et ne s'en va plus (relecture du 2026-10-12) : repassé « Actif », il gardait sa date
        // de fin, qui borne déjà les fiches et le propriétaire, et redevenait « À solder ».
        if ($contract->status === 'completed' && $data->status !== 'completed') {
            throw ValidationException::withMessages(['status' => 'Un contrat soldé ne change plus de statut.']);
        }

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

            // Le montant investi : laissé vide, le contrat garde le sien (2026-10-14).
            if (($data['invested_amount'] ?? null) !== null) {
                $updateData['invested_amount'] = $data['invested_amount'];
            }

            // Repasser en attente : seulement un contrat qui n'a jamais roulé (2026-10-09).
            if ($updateData['status'] === 'pending') {
                $started = $contract->driverContracts()->exists()
                    || $contract->payments()->exists()
                    || $contract->remunerationStatements()->exists();
                if ($started) {
                    throw new ApiException(409, 'VEHICLE_CONTRACT_ALREADY_STARTED', 'Ce contrat a déjà eu un agent, un paiement ou une fiche : il ne repasse pas en attente.');
                }
                $updateData['start_date'] = null;
            }

            ($this->checkTotal)((int) $updateData['contract_months'], $updateData['total_amount'], $contract);

            // Les montants journaliers restent ceux du contrat, sauf changement de durée :
            // ils suivent alors les réglages de la nouvelle.
            if ((int) $updateData['contract_months'] !== (int) $contract->contract_months) {
                $updateData = [...$updateData, ...ContractTerms::dailyAmountsFor((int) $updateData['contract_months'])];
            }

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

            if (in_array($updateData['status'], ['active', 'pending'], true) && $vehicle) {
                VehicleContractRules::assertCanCarryAnActiveContract($vehicle, except: $contract);
            }

            $previousStatus = $contract->status;
            $contract->update($updateData);

            // Les étapes de préparation suivent le statut d'ARRIVÉE, d'où qu'on vienne, annulé
            // compris (spec 2026-10-20, §4.3 bis ; relecture finale) : actif = arrivée cochée,
            // en attente = arrivée à faire, et la liste existe.
            if ($contract->status === 'active' && $previousStatus !== 'active') {
                PreparationSteps::markAgentArrival($contract, $contract->start_date->toDateString());
            } elseif ($contract->status === 'pending' && $previousStatus !== 'pending') {
                PreparationSteps::reopen($contract);
            }

            return $contract->refresh();
        });
    }
}
