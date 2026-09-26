<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Domains\Workforce\Application\Data\AdminDriverContractListItemData;
use App\Domains\Workforce\Application\Data\AdminDriverContractPageData;
use App\Models\DriverContract;
use Illuminate\Database\Eloquent\Builder;

/** La liste des contrats agents — ex-Admin\DriverContractController::index(). */
final class ListDriverContracts
{
    public function __invoke(): AdminDriverContractPageData
    {
        return new AdminDriverContractPageData(
            contracts: self::query()
                ->latest()
                ->get()
                ->map(fn (DriverContract $contract) => AdminDriverContractListItemData::fromModel($contract))
                ->all(),
        );
    }

    /** Ce qu'attend `AdminDriverContractListItemData::fromModel()`. */
    public static function query(): Builder
    {
        return DriverContract::query()
            ->with(['driver.user', 'vehicle'])
            ->withCount(['leaveRequests', 'payments']);
    }
}
