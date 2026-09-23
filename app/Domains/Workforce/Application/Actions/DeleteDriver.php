<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Models\Driver;
use App\Services\DriverService;
use App\Shared\Http\ApiException;

/** Supprimer un agent — ex-Admin\DriverController::destroy(). */
final class DeleteDriver
{
    public function __construct(private readonly DriverService $driverService) {}

    public function __invoke(Driver $driver): void
    {
        if ($driver->bookings()->whereIn('status', ['confirmed', 'in_progress'])->exists()) {
            throw new ApiException(
                409,
                'DRIVER_NOT_DELETABLE',
                'Impossible de supprimer un agent avec des courses en cours.'
            );
        }

        // `DriverService::deleteDriver()` prend l'identifiant du COMPTE (`users.id`), pas
        // celui de l'agent — pont entre les deux conventions, comme `UpdateDriverPassword`.
        $this->driverService->deleteDriver($driver->user_id);
    }
}
