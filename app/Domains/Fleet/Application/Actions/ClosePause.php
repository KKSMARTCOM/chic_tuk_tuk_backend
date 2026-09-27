<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Notification\Application\Notifier;
use App\Models\VehiclePause;
use Illuminate\Support\Facades\DB;

/**
 * Clôt une pause de véhicule — ex-`VehicleService::endPause()`, déplacé sans changement le
 * 2026-09-27 : la fin d'une pause véhicule et la fin d'une pause d'agent le partagent.
 */
final class ClosePause
{
    // Terminer une pause véhicule
    public function __invoke(VehiclePause $pause, ?string $endDate = null): VehiclePause
    {
        $termine = DB::transaction(function () use ($pause, $endDate) {
            $pause->update(['end_date' => $endDate ?? now()->toDateString()]);

            // Réactiver le véhicule si pas de pause active
            if (! $pause->vehicle->activePause) {
                $pause->vehicle->update(['is_active' => true]);
            }

            return $pause->refresh();
        });

        // Le propriétaire apprend la reprise sans avoir à ouvrir l'application.
        app(Notifier::class)->vehiclePauseEnded($termine);

        return $termine;
    }
}
