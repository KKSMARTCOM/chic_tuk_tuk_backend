<?php

namespace App\Domains\Workforce\Domain\Enums;

use App\Shared\Enums\HasOptions;

/**
 * Statut d'une demande de pause — colonne `leave_requests.status`.
 *
 * Source de vérité : contrainte CHECK `leave_requests_status_check` dans son état
 * courant. La valeur « approved » figurait dans la migration initiale mais a été
 * retirée par la migration 2026_08_12_220013 : une demande acceptée passe
 * directement à « ongoing ».
 */
enum LeaveStatus: string
{
    use HasOptions;

    case Pending   = 'pending';
    case Ongoing   = 'ongoing';
    case Completed = 'completed';
    case Rejected  = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'En attente',
            self::Ongoing   => 'En cours',
            self::Completed => 'Terminé',
            self::Rejected  => 'Refusé',
        };
    }
}
