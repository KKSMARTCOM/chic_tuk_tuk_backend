<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Domains\Workforce\Application\Data\AdminDriverDetailData;
use App\Models\Driver;
use App\Services\CommissionService;
use App\Services\DriverService;

/** Le dossier d'un agent — ex-Admin\DriverController::show(). */
final class ShowDriverDetail
{
    public function __construct(
        private readonly DriverService $driverService,
        private readonly CommissionService $commissionService,
    ) {}

    public function __invoke(string $driverId): AdminDriverDetailData
    {
        $driver = Driver::with('user')->findOrFail($driverId);

        return AdminDriverDetailData::fromModel($driver, $this->driverService, $this->commissionService);
    }
}
