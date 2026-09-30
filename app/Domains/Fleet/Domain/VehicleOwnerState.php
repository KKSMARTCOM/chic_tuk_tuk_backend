<?php

namespace App\Domains\Fleet\Domain;

use App\Models\VehiclePause;

/**
 * L'état d'un véhicule pour son propriétaire : Actif, En pause, ou Immobilisé — en attente
 * d'un nouvel agent, le seul cas où la maquette annonce « Véhicule immobilisé en attente
 * d'un nouvel agent ». Tout autre motif de pause s'affiche « En pause », avec son motif.
 */
final class VehicleOwnerState
{
    public static function of(?VehiclePause $activePause): string
    {
        return match (true) {
            $activePause === null => 'active',
            $activePause->reason_type === 'agent_change' => 'immobilized',
            default => 'paused',
        };
    }
}
