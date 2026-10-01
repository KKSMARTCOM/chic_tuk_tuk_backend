<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Application\Data\AdminPaymentDriverOptionData;
use App\Models\Driver;

/**
 * Les agents proposés au formulaire de paiement : ceux qui ont un contrat actif, comme
 * `Admin\PaymentController::create()`. Pour un paiement de CONTRAT (`$type = 'contract'`),
 * tous ceux qui en ont eu un, démissionnaires compris (2026-10-01).
 */
final class ListPayableDrivers
{
    /** @return array<int, AdminPaymentDriverOptionData> */
    public function __invoke(?string $type = null): array
    {
        return Driver::query()
            ->when($type === 'contract',
                fn ($query) => $query->whereHas('driverContracts'),
                fn ($query) => $query->whereHas('driverContracts', fn ($q) => $q->where('status', 'active')))
            ->with(['user', 'activeDriverContract.vehicle', 'driverContracts.vehicle'])
            ->get()
            ->sortBy(fn (Driver $driver) => $driver->user?->name)
            ->map(fn (Driver $driver) => AdminPaymentDriverOptionData::fromModel($driver))
            ->values()
            ->all();
    }
}
