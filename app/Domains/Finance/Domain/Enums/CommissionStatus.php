<?php

namespace App\Domains\Finance\Domain\Enums;

use App\Shared\Enums\HasOptions;

/**
 * Statut d'une commission — colonne `commissions.status`, chaîne libre en base.
 *
 * `active` : l'agent la doit. `cancelled` : annulée par l'administration — elle ne compte
 * plus dans ce qu'il doit, mais reste visible (2026-09-26 ; le Blade la supprimait).
 */
enum CommissionStatus: string
{
    use HasOptions;

    case Active = 'active';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Due',
            self::Cancelled => 'Annulée',
        };
    }
}
