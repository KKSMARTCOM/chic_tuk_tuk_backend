<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Models\VehiclePause;
use App\Shared\Http\ApiException;

/**
 * Annuler une pause véhicule posée par erreur — ex-Admin\VehicleController::destroyPause().
 *
 * Seule une pause MANUELLE s'annule ici : voir `cancelManualPause()` ci-dessous.
 */
final class CancelVehiclePause
{
    public function __construct(private readonly CancelPause $cancelPause) {}

    public function __invoke(VehiclePause $pause): void
    {
        $this->cancelManualPause($pause);
    }

    /**
     * Annuler une pause posée À LA MAIN par erreur — décidé le 2026-09-25.
     *
     * Une pause automatique suit la pause d'un agent : l'annuler ici désynchroniserait les
     * deux. Elle se corrige ou se supprime depuis l'écran des pauses des agents, qui passe
     * par `CancelPause`.
     */
    private function cancelManualPause(VehiclePause $pause): void
    {
        if ($pause->is_auto) {
            throw new ApiException(
                409,
                'VEHICLE_PAUSE_AUTOMATIC',
                "Cette pause suit la pause d'un agent : elle se gère depuis l'écran des pauses."
            );
        }

        ($this->cancelPause)($pause);
    }
}
