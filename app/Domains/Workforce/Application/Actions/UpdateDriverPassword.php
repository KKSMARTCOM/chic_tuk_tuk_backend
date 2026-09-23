<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Domains\Workforce\Application\Data\UpdateDriverPasswordData;
use App\Models\Driver;
use App\Services\DriverService;

/** Réinitialiser le mot de passe d'un agent — ex-Admin\DriverController::updatePassword(). */
final class UpdateDriverPassword
{
    public function __construct(private readonly DriverService $driverService) {}

    public function __invoke(Driver $driver, UpdateDriverPasswordData $data): void
    {
        // `DriverService::updateDriverPassword()` prend l'identifiant du COMPTE
        // (`users.id`), pas celui de l'agent — pont entre les deux conventions.
        $this->driverService->updateDriverPassword($driver->user_id, $data->password);
    }
}
