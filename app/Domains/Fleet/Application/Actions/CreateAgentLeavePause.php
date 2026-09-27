<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Notification\Application\Notifier;
use App\Models\Vehicle;
use App\Models\VehiclePause;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pose la pause automatique du véhicule d'un agent en pause — ex-
 * `VehicleService::createAutoAgentPause()`, déplacé sans changement le 2026-09-27.
 */
final class CreateAgentLeavePause
{
    // Créer automatiquement une pause véhicule suite à l'absence d'un agent
    public function __invoke(string $vehicleId, string $driverContractId, string $startDate, ?string $endDate = null): VehiclePause
    {
        $pause = DB::transaction(function () use ($vehicleId, $driverContractId, $startDate, $endDate) {
            $vehicle = Vehicle::findOrFail($vehicleId);
            $start = Carbon::parse($startDate)->startOfDay();
            $end = $endDate ? Carbon::parse($endDate)->startOfDay() : null;
            $today = Carbon::today();

            // Déterminer si la pause est passée, présente ou future
            $isPast = $end && $end->lt($today);
            $isCurrent = $start->lte($today) && (! $end || $end->gte($today));
            // $isFuture  = $startDate->gt($today);  // implicite

            // ── Clôturer la pause active éventuelle ──────────────
            // Uniquement si la nouvelle pause commence aujourd'hui ou dans le futur
            if (! $isPast && $vehicle->activePause) {
                $vehicle->activePause->update(['end_date' => $start->toDateString()]);
            }

            // ── Créer la pause véhicule ───────────────────────────
            $pause = VehiclePause::create([
                'vehicle_id' => $vehicle->id,
                'vehicle_contract_id' => $vehicle->activeVehicleContract?->id,
                'driver_contract_id' => $driverContractId,
                'start_date' => $start->toDateString(),
                'end_date' => $end?->toDateString(), // toujours renseigné car on connaît les dates
                'reason_type' => 'agent_leave',
                'reason_notes' => 'Pause automatique suite à une pause agent — '
                    .($isPast ? 'passé' : ($isCurrent ? 'en cours' : 'futur'))
                    .'.',
                'is_auto' => true,
            ]);

            // ── Désactiver le véhicule uniquement si la pause est en cours ──
            // Passée → le véhicule n'est plus en pause, rien à changer
            // Présente → désactiver
            // Future → on ne touche pas à is_active maintenant (sera géré par un job ou à la date)
            if ($isCurrent) {
                $vehicle->update(['is_active' => false]);
            }

            return $pause;
        });

        // ⚠️ Le propriétaire est prévenu ICI AUSSI, et pas seulement sur une pause
        // manuelle : une pause née de l'absence d'un agent immobilise son véhicule tout autant,
        // et c'est le cas le plus fréquent. Le motif dit « Pause agent ».
        app(Notifier::class)->vehiclePaused($pause);

        return $pause;
    }
}
