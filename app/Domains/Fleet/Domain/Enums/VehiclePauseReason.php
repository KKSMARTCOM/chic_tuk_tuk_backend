<?php

namespace App\Domains\Fleet\Domain\Enums;

use App\Shared\Enums\HasOptions;

/**
 * Motif de mise en pause d'un véhicule — colonne `vehicle_pauses.reason_type`.
 *
 * AgentLeave correspond aux pauses véhicule créées automatiquement par l'absence d'un agent
 * (indicateur `is_auto`), les autres sont saisies manuellement.
 */
enum VehiclePauseReason: string
{
    use HasOptions;

    case AgentLeave  = 'agent_leave';
    case AgentChange = 'agent_change';
    case Technical   = 'technical';
    case Accident    = 'accident';
    case Legal       = 'legal';
    case Other       = 'other';

    public function label(): string
    {
        return match ($this) {
            self::AgentLeave  => 'Pause agent',
            self::AgentChange => 'Changement d\'agent',
            self::Technical   => 'Problème technique',
            self::Accident    => 'Accident',
            self::Legal       => 'Litige / problème légal',
            self::Other       => 'Autre',
        };
    }
}
