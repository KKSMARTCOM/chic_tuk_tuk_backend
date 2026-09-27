<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\CreateVehiclePauseData;
use App\Domains\Notification\Application\Notifier;
use App\Models\Vehicle;
use App\Models\VehiclePause;
use App\Shared\Http\ApiException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Mettre un véhicule en pause — ex-Admin\VehicleController::addPause(). */
final class PauseVehicle
{
    public function __invoke(Vehicle $vehicle, CreateVehiclePauseData $data): VehiclePause
    {
        return $this->pauseVehicle($vehicle, $data->toServicePayload());
    }

    // Mettre le véhicule en pause manuellement
    private function pauseVehicle(Vehicle $vehicle, array $data): VehiclePause
    {
        $pause = DB::transaction(function () use ($vehicle, $data) {
            // Vérifier si il y a un contrat actif
            $activeContract = $vehicle->activeVehicleContract;
            if (! $activeContract) {
                throw new ApiException(
                    409,
                    'VEHICLE_WITHOUT_CONTRACT',
                    "Impossible de mettre le véhicule en pause car il n'a pas de contrat actif."
                );
            }

            // Clôturer la pause active si existante
            if ($vehicle->activePause) {
                $vehicle->activePause->update(['end_date' => $data['start_date'] ?? now()->toDateString()]);
            }

            // Créer une nouvelle pause
            $pause = VehiclePause::create([
                'vehicle_id' => $vehicle->id,
                'vehicle_contract_id' => $vehicle->activeVehicleContract?->id,
                'driver_contract_id' => $data['driver_contract_id'] ?? null,
                'start_date' => $data['start_date'] ?? now()->toDateString(),
                'end_date' => $data['end_date'] ?? null,
                'reason_type' => $data['reason_type'] ?? 'manual',
                'reason_notes' => $data['reason_notes'] ?? null,
                'is_auto' => false,
            ]);

            // Désactiver le véhicule seulement si la pause couvre AUJOURD'HUI — la règle de
            // `createAutoAgentPause()`. Corrigé le 2026-09-25 : une pause posée avec une
            // date de fin passée désactivait quand même le véhicule, et comme
            // `activePause` ne voit que les pauses sans date de fin, il restait inactif,
            // sans pause en cours ni bouton pour y mettre fin.
            $today = Carbon::today();
            $start = Carbon::parse($pause->start_date)->startOfDay();
            $end = $pause->end_date ? Carbon::parse($pause->end_date)->startOfDay() : null;

            if ($start->lte($today) && (! $end || $end->gte($today))) {
                $vehicle->update(['is_active' => false]);
            }

            return $pause;
        });

        // Le propriétaire est prévenu, avec le MOTIF : un véhicule à l'arrêt sans
        // explication déclenche un appel à l'administration, et c'est cet appel que la
        // notification remplace.
        app(Notifier::class)->vehiclePaused($pause);

        return $pause;
    }
}
