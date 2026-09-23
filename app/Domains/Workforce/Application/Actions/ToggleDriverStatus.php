<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Models\Driver;

/**
 * Activer ou désactiver le COMPTE d'un agent — ex-Admin\DriverController::toggleStatus().
 *
 * ⚠️ Porte sur `users.is_active`, pas `drivers.is_available` — voir `ToggleDriverAvailability`
 * pour l'autre bascule. Un compte désactivé et un agent indisponible sont deux états
 * distincts dans le Blade, et le rester ici.
 */
final class ToggleDriverStatus
{
    public function __invoke(Driver $driver, bool $isActive): void
    {
        $driver->user->update(['is_active' => $isActive]);
    }
}
