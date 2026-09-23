<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Domains\Workforce\Application\Data\CreateDriverData;
use App\Models\User;
use App\Services\DriverService;
use App\Shared\Http\ApiException;

/** Créer un agent — ex-Admin\DriverController::store(). */
final class CreateDriver
{
    public function __construct(private readonly DriverService $driverService) {}

    public function __invoke(CreateDriverData $data): User
    {
        try {
            return $this->driverService->createDriver($data->toServicePayload());
        } catch (\Exception $e) {
            // `DriverService::createDriver()` lève des \Exception génériques (véhicule
            // sans contrat actif, règle « 1 véhicule = 1 agent »...) — le même chemin
            // que le Blade, qui les affiche telles quelles en message flash.
            throw new ApiException(422, 'DRIVER_CREATE_FAILED', $e->getMessage());
        }
    }
}
