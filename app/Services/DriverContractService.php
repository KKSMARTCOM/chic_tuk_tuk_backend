<?php

namespace App\Services;

use App\Models\DriverContract;
use App\Models\Vehicle;
use App\Models\VehiclePause;
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

    public function update(DriverContract $contract, array $data, Vehicle $vehicle): DriverContract
    {
        $contract->update([
            'vehicle_id'      => $vehicle->id,
            'start_date'      => $data['start_date'],
            'contract_months' => $data['contract_months'],
        ]);

        return $contract->refresh();
    }

    public function end(DriverContract $contract, array $data): DriverContract
    {
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
                'reason_notes'        => $data['end_reason'] . ($data['end_notes'] ? ' — ' . $data['end_notes'] : ''),
                'is_auto'             => true,
            ]);

            return $contract->refresh();
        });
    }

    public function delete(DriverContract $contract): void
    {
        if ($contract->status === 'active') {
            throw new \Exception('Un contrat actif ne peut pas être supprimé. Terminez-le d\'abord.');
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
            throw new \Exception(
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
            throw new \Exception(
                "Le propriétaire {$owner->name} n'a pas d'autre véhicule disponible. "
                    . "Il possède {$ownerVehiclesCount} véhicule(s) et a déjà {$activeAgentsCount} agent(s) actif(s)."
            );
        }
    }
}
