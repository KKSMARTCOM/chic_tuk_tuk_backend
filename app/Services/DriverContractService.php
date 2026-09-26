<?php

namespace App\Services;

use App\Models\DriverContract;
use App\Models\Vehicle;
use App\Models\VehiclePause;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

class DriverContractService
{
    public function create(array $data): DriverContract
    {
        // Terminer tout contrat actif sur ce véhicule
        DriverContract::where('vehicle_id', $data['vehicle_id'])
            ->where('status', 'active')
            ->update([
                'status'     => 'ended',
                'end_date'   => now()->toDateString(),
                'end_reason' => 'new_contract',
            ]);

        return DriverContract::create([
            'driver_id'           => $data['driver_id'],
            'vehicle_id'          => $data['vehicle_id'],
            'vehicle_contract_id' => $data['vehicle_contract_id'],
            'start_date'          => $data['start_date'],
            'end_date'            => null,
            'contract_months'     => $data['contract_months'],
            'status'              => 'active',
        ]);
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
    public function update(DriverContract $contract, array $data, Vehicle $vehicle): DriverContract
    {
        return DB::transaction(function () use ($contract, $data, $vehicle) {
            if ($this->hasHistory($contract)) {
                throw new ApiException(
                    409,
                    'DRIVER_CONTRACT_LOCKED',
                    'Ce contrat ne peut plus être modifié directement car il a des pauses ou paiements liés. '
                        .'Pour changer de véhicule, terminez ce contrat et créez-en un nouveau.'
                );
            }

            $updateData = [
                'start_date'      => $data['start_date'],
                'contract_months' => $data['contract_months'],
            ];

            if ($vehicle->id !== $contract->vehicle_id) {
                $this->validateVehicleAssignment($vehicle, $contract->driver_id, $contract->id);

                $vehicleContract = $vehicle->activeVehicleContract;
                if (!$vehicleContract) {
                    throw new ApiException(
                        409,
                        'VEHICLE_WITHOUT_CONTRACT',
                        "Le véhicule {$vehicle->vehicle_number} n'a pas de contrat véhicule actif."
                    );
                }

                $updateData['vehicle_id'] = $vehicle->id;
                $updateData['vehicle_contract_id'] = $vehicleContract->id;
            }

            $contract->update($updateData);

            return $contract->refresh();
        });
    }

    /** Des pauses agent ou des paiements : le contrat a servi. */
    public function hasHistory(DriverContract $contract): bool
    {
        return $contract->leaveRequests()->exists() || $contract->payments()->exists();
    }

    /**
     * Termine un contrat actif : pause véhicule « changement d'agent », véhicule désactivé,
     * compteur de pauses de l'agent remis à zéro.
     *
     * Corrigé le 2026-09-26 : un contrat déjà terminé pouvait l'être une seconde fois, ce
     * qui créait une seconde pause véhicule automatique.
     */
    public function end(DriverContract $contract, array $data): DriverContract
    {
        if ($contract->status !== 'active') {
            throw new ApiException(409, 'DRIVER_CONTRACT_NOT_ACTIVE', 'Ce contrat est déjà terminé.');
        }

        return DB::transaction(function () use ($contract, $data) {
            $contract->update([
                'status'     => 'ended',
                'end_date'   => $data['end_date'] ?? now()->toDateString(),
                'end_reason' => $data['end_reason'],
                'end_notes'  => $data['end_notes'] ?? null,
            ]);

            // Réinitialiser les jours de pause utilisés pour le conducteur
            $contract->driver->update([
                'leave_days_used' => 0,
                'leave_dates'     => [],
            ]);

            // Marquer le véhicule comme inactif
            $contract->vehicle->update([
                'is_active' => false,
            ]);

            // Créer une pause véhicule pour changement d'agent
            VehiclePause::create([
                'vehicle_id'          => $contract->vehicle_id,
                'vehicle_contract_id' => $contract->vehicle_contract_id,
                'driver_contract_id'  => $contract->id,
                'start_date'          => $data['end_date'] ?? now()->toDateString(),
                'end_date'            => null, // sera fermée à la création du prochain contrat agent
                'reason_type'         => 'agent_change',
                'reason_notes'        => $data['end_reason'] . (!empty($data['end_notes']) ? ' — ' . $data['end_notes'] : ''),
                'is_auto'             => true,
            ]);

            return $contract->refresh();
        });
    }

    /**
     * Supprime un contrat terminé qui n'a pas servi.
     *
     * Décidé le 2026-09-26, comme pour les contrats véhicule : un contrat qui a des pauses
     * agent ou des paiements ne se supprime plus. Leurs clés passeraient à null, et les
     * pauses sortiraient du solde de l'agent, calculé contrat par contrat.
     *
     * Des `ApiException` : le Blade les affiche en message flash comme avant.
     */
    public function delete(DriverContract $contract): void
    {
        if ($contract->status === 'active') {
            throw new ApiException(
                409,
                'DRIVER_CONTRACT_ACTIVE',
                'Un contrat actif ne peut pas être supprimé. Terminez-le d\'abord.'
            );
        }

        if ($this->hasHistory($contract)) {
            throw new ApiException(
                409,
                'DRIVER_CONTRACT_NOT_DELETABLE',
                'Impossible de supprimer ce contrat : il a des pauses ou des paiements.'
            );
        }

        $contract->delete();
    }

    public function getStats(DriverContract $contract): array
    {
        $usedDays    = $contract->used_leave_days;
        $accruedDays = $contract->accrued_leave_days;
        $available   = $accruedDays - $usedDays;
        $surplus     = $available < 0 ? abs($available) : 0;
        $remainingLeaveDays = $contract->remaining_contract_leave_days;

        return [
            'accrued_leave_days'  => $accruedDays,
            'used_leave_days'     => $usedDays,
            'available_leave_days' => max(0, $available),
            'remaining_leave_days' => $remainingLeaveDays,
            'surplus_leave_days'  => $surplus,
            'months_elapsed'      => $contract->months_elapsed,
            'total_paid'          => $contract->total_paid,
        ];
    }

    public function validateVehicleAssignment(Vehicle $vehicle, ?string $excludeDriverId = null, ?string $excludeContractId = null): void
    {
        // ── Règle 1 : véhicule déjà pris par un autre agent ─────
        $vehicleQuery = DriverContract::where('vehicle_id', $vehicle->id)->where('status', 'active');

        if ($excludeDriverId) {
            $vehicleQuery->where('driver_id', '!=', $excludeDriverId);
        }

        // Exclure le contrat qu'on est en train de modifier
        if ($excludeContractId) {
            $vehicleQuery->where('id', '!=', $excludeContractId);
        }

        if ($vehicleQuery->exists()) {
            throw new ApiException(
                409,
                'VEHICLE_ALREADY_ASSIGNED',
                "Le véhicule {$vehicle->vehicle_number} est déjà assigné à un autre agent actif."
            );
        }

        // ── Règle 2 : un agent par véhicule du propriétaire ─────
        $owner = $vehicle->owner;

        if (!$owner) return;

        //Récupérer les véhicules disponibles du propriétaire
        $ownerVehicleIds = $owner->vehicles()->pluck('id');

        $activeAgentsQuery = DriverContract::whereIn('vehicle_id', $ownerVehicleIds)->where('status', 'active');

        if ($excludeDriverId) {
            $activeAgentsQuery->where('driver_id', '!=', $excludeDriverId);
        }

        // Exclure le contrat en cours de modification du comptage
        if ($excludeContractId) {
            $activeAgentsQuery->where('id', '!=', $excludeContractId);
        }

        $activeAgentsCount  = $activeAgentsQuery->count();
        $ownerVehiclesCount = $ownerVehicleIds->count();

        if ($activeAgentsCount >= $ownerVehiclesCount) {
            throw new ApiException(
                409,
                'OWNER_HAS_NO_FREE_VEHICLE',
                "Le propriétaire {$owner->name} n'a pas d'autre véhicule disponible. "
                    . "Il possède {$ownerVehiclesCount} véhicule(s) et a déjà {$activeAgentsCount} agent(s) actif(s)."
            );
        }
    }
}
