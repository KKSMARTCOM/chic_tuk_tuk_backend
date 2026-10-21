<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Models\Driver;

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
        $driver->user->update(['is_active' => $isActive]);
    }
}
