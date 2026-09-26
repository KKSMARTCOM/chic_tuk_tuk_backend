<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Application\Data\AdminPaymentDriverOptionData;
use App\Models\Driver;

/**
 * Les agents proposés au formulaire de paiement : ceux qui ont un contrat actif, comme
 * `Admin\PaymentController::create()`.
 *
 * @return array<int, AdminPaymentDriverOptionData>
 */
final class ListPayableDrivers
{
    public function __invoke(): array
    {
        return Driver::query()
            ->whereHas('driverContracts', fn ($query) => $query->where('status', 'active'))
            ->with(['user', 'activeDriverContract.vehicle'])
            ->get()
            ->sortBy(fn (Driver $driver) => $driver->user?->name)
            ->map(fn (Driver $driver) => AdminPaymentDriverOptionData::fromModel($driver))
            ->values()
            ->all();
    }
}
