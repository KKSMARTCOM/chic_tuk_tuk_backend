<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Domains\Workforce\Application\Data\AdminOwnerRenewalOptionData;
use App\Models\User;

/**
 * Les propriétaires proposés au sélecteur « Reconduire un contrat » de la
 * création/édition d'agent — ex-Admin\DriverController::create(), bloc
 * `$ownersForRenewal`/`$ownerVehiclesForRenewal`.
 */
final class ListOwnersForRenewal
{
    /** @return array<int, AdminOwnerRenewalOptionData> */
    public function __invoke(): array
    {
        $owners = User::whereHas('roles', fn ($q) => $q->where('name', 'proprietaire'))
            ->where('is_active', true)
            ->whereHas('vehicles.driverContracts', fn ($q) => $q->where('status', 'ended'))
            ->whereHas('vehicles.activeVehicleContract')
            ->whereDoesntHave('vehicles.activeDriverContract')
            ->with(['vehicles' => fn ($q) => $q
                ->whereHas('driverContracts', fn ($q2) => $q2->where('status', 'ended'))
                ->whereHas('activeVehicleContract')
                ->whereDoesntHave('activeDriverContract')
                ->with([
                    'activeVehicleContract',
                    'driverContracts' => fn ($q2) => $q2->where('status', 'ended')->latest('end_date'),
                ])])
            ->orderBy('name')
            ->get(['id', 'name', 'phone']);

        return $owners->map(fn (User $owner) => AdminOwnerRenewalOptionData::fromModel($owner))->all();
    }
}
