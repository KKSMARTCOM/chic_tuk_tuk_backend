<?php

namespace App\Domains\Fleet\Domain\Enums;

use App\Shared\Enums\HasOptions;

/**
 * Statut d'un contrat propriétaire — colonne `vehicle_contracts.status`
 * (chaîne libre en base, valeurs imposées par la validation : pending/active/completed/cancelled).
 */
enum VehicleContractStatus: string
{
    use HasOptions;

    case Pending   = 'pending';
    case Active    = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** Libellés repris à l'identique des vues de contrats. */
    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'En attente',
            self::Active    => 'Actif',
            self::Completed => 'Soldé',
            self::Cancelled => 'Annulé',
        };
    }
}
