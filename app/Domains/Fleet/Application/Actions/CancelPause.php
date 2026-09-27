<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Models\VehiclePause;
use Illuminate\Support\Facades\DB;

/**
 * Annule une pause de véhicule — ex-`VehicleService::cancelPause()`, déplacé sans
 * changement le 2026-09-27.
 */
final class CancelPause
{
    /**
     * ANNULER une pause véhicule : la supprimer, comme si elle n'avait jamais existé.
     *
     * ⚠️ À distinguer de `endPause()`, qui la CLÔTURE et en garde la trace. On annule une
     * pause posée par erreur ; on clôture une pause qui a réellement eu lieu. Confondre
     * les deux laisserait dans l'historique du propriétaire une immobilisation fictive.
     *
     * ⚠️ Si cette pause en avait clôturé une précédente à sa création — ce que fait
     * `createAutoAgentPause` —, la précédente n'est PAS rouverte : on ne sait pas si sa
     * clôture était juste par ailleurs. Cas rare, signalé plutôt que deviné.
     */
    public function __invoke(VehiclePause $pause): void
    {
        DB::transaction(function () use ($pause) {
            $vehicule = $pause->vehicle;
            $pause->delete();

            // Le véhicule reprend du service si plus rien ne le retient.
            if ($vehicule && ! $vehicule->fresh()->activePause) {
                $vehicule->update(['is_active' => true]);
            }
        });
    }
}
