<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Domains\Finance\Application\Actions\BuildDriverSituation;
use App\Domains\Finance\Application\Actions\ComputeDriverSubscriptionRevenue;
use App\Domains\Finance\Application\Actions\SummarizeDriverCommissions;
use App\Domains\Workforce\Application\Data\AdminDriverDetailData;
use App\Models\Driver;

/** Le dossier d'un agent — ex-Admin\DriverController::show(). */
final class ShowDriverDetail
{
    public function __construct(
        private readonly ComputeDriverBookingStats $computeBookingStats,
        private readonly SummarizeDriverCommissions $summarizeCommissions,
        private readonly ComputeDriverSubscriptionRevenue $computeRevenue,
        private readonly BuildDriverSituation $buildSituation,
    ) {}

    public function __invoke(string $driverId): AdminDriverDetailData
    {
        $driver = Driver::with('user')->findOrFail($driverId);

        return AdminDriverDetailData::fromModel($driver, $this->computeBookingStats, $this->summarizeCommissions, $this->computeRevenue, $this->buildSituation);
    }
}
