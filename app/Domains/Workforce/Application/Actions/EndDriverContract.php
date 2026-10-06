<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Domains\Workforce\Application\Data\EndDriverContractData;
use App\Models\DriverContract;
use App\Models\VehiclePause;
use App\Shared\Http\ApiException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Terminer un contrat agent — ex-Admin\DriverContractController::end().
 *
 * ⚠️ Effets de bord, dans `endContract()` ci-dessous (ex-`DriverContractService::end()`) : le véhicule est désactivé,
 * une pause véhicule automatique « changement d'agent » s'ouvre à la date de fin, et le
 * compteur de pauses de l'agent est remis à zéro.
 */
final class EndDriverContract
{
    public function __invoke(DriverContract $contract, EndDriverContractData $data): DriverContract
    {
        return $this->endContract($contract, $data->toServicePayload());
    }

    /**
     * Termine un contrat actif : pause véhicule « changement d'agent », véhicule désactivé,
     * compteur de pauses de l'agent remis à zéro.
     *
     * Corrigé le 2026-09-26 : un contrat déjà terminé pouvait l'être une seconde fois, ce
     * qui créait une seconde pause véhicule automatique.
     */
    private function endContract(DriverContract $contract, array $data): DriverContract
    {
        if ($contract->status !== 'active') {
            throw new ApiException(409, 'DRIVER_CONTRACT_NOT_ACTIVE', 'Ce contrat est déjà terminé.');
        }

        // ⚠️ Corrigé le 2026-10-06 : deux contrats de production se sont terminés avant
        // d'avoir commencé, ce qui sortait tous leurs jours du contrat.
        $endDate = Carbon::parse($data['end_date'] ?? now()->toDateString())->startOfDay();
        if ($endDate->lt($contract->start_date->copy()->startOfDay())) {
            throw new ApiException(
                422,
                'DRIVER_CONTRACT_END_BEFORE_START',
                'La date de fin ne peut pas précéder le début du contrat ('.$contract->start_date->format('d/m/Y').').'
            );
        }

        return DB::transaction(function () use ($contract, $data) {
            $contract->update([
                'status' => 'ended',
                'end_date' => $data['end_date'] ?? now()->toDateString(),
                'end_reason' => $data['end_reason'],
                'end_notes' => $data['end_notes'] ?? null,
            ]);

            // Réinitialiser les jours de pause utilisés pour le conducteur
            $contract->driver->update([
                'leave_days_used' => 0,
                'leave_dates' => [],
            ]);

            // Marquer le véhicule comme inactif
            $contract->vehicle->update([
                'is_active' => false,
            ]);

            // Créer une pause véhicule pour changement d'agent
            VehiclePause::create([
                'vehicle_id' => $contract->vehicle_id,
                'vehicle_contract_id' => $contract->vehicle_contract_id,
                'driver_contract_id' => $contract->id,
                'start_date' => $data['end_date'] ?? now()->toDateString(),
                'end_date' => null, // sera fermée à la création du prochain contrat agent
                'reason_type' => 'agent_change',
                'reason_notes' => $data['end_reason'].(! empty($data['end_notes']) ? ' — '.$data['end_notes'] : ''),
                'is_auto' => true,
            ]);

            return $contract->refresh();
        });
    }
}
