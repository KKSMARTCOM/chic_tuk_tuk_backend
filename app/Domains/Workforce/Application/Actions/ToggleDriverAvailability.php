<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Models\Driver;

/** Marquer un agent disponible ou non — ex-Admin\DriverController::toggleAvailability(). */
final class ToggleDriverAvailability
{
    public function __invoke(Driver $driver, bool $isAvailable): void
    {
        $driver->update(['is_available' => $isAvailable]);
    }
}
