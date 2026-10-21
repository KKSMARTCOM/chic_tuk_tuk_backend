<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Models\Driver;
use App\Shared\Http\ApiException;

/**
 * Activer ou désactiver le COMPTE d'un agent — ex-Admin\DriverController::toggleStatus().
 *
 * ⚠️ Porte sur `users.is_active`, pas `drivers.is_available`, que décide `SyncDriverAvailability`.
 * Réservée à l'administrateur (`manage-driver-accounts`, 2026-10-21) : le départ d'un agent
 * ferme son compte, et lui seul le rouvre.
 */
final class ToggleDriverStatus
{
    public function __invoke(Driver $driver, bool $isActive): void
    {
        // Un agent en service (contrat ou affectation interne) se retire par la fin de son
        // contrat, qui ferme aussi son compte : le fermer seul le laisserait sous contrat et
        // « disponible », sans accès (relecture du 2026-10-21).
        $inService = $driver->activeDriverContract()->exists() || $driver->activeInternalAssignment()->exists();
        if (! $isActive && $inService) {
            throw new ApiException(
                409,
                'DRIVER_ACCOUNT_IN_SERVICE',
                'Cet agent est en service : terminez d\'abord son contrat ou son affectation, ce qui fermera son compte.'
            );
        }

        $driver->user->update(['is_active' => $isActive]);
    }
}
