<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Models\Driver;
use App\Models\User;
use App\Shared\Http\ApiException;

/** Supprimer un agent — ex-Admin\DriverController::destroy(). */
final class DeleteDriver
{
    public function __invoke(Driver $driver): void
    {
        if ($driver->bookings()->whereIn('status', ['confirmed', 'in_progress'])->exists()) {
            throw new ApiException(
                409,
                'DRIVER_NOT_DELETABLE',
                'Impossible de supprimer un agent avec des courses en cours.'
            );
        }

        // `deleteDriver()` prend l'identifiant du COMPTE (`users.id`), pas
        // celui de l'agent — pont entre les deux conventions, comme `UpdateDriverPassword`.
        $this->deleteDriver($driver->user_id);
    }

    private function deleteDriver(string $driverId)
    {
        $user = User::findOrFail($driverId);

        // Vérifier s'il a des courses en cours
        if ($user->driver && $user->driver->bookings()->whereIn('status', ['confirmed', 'in_progress'])->exists()) {
            throw new \Exception('Impossible de supprimer un Agent avec des courses en cours.');
        }

        $user->delete();
    }
}
