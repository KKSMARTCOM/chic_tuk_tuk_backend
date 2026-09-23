<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Domains\Workforce\Application\Data\AdminOwnerOptionData;
use App\Models\User;

/**
 * Les propriétaires proposés au sélecteur « Nouveau contrat » de la création/édition
 * d'agent — ex-Admin\DriverController::create(), bloc `$owners`/`$ownerVehicles`.
 */
final class ListOwnersForNewContract
{
    /** @return array<int, AdminOwnerOptionData> */
    public function __invoke(): array
    {
        $owners = User::whereHas('roles', fn ($q) => $q->where('name', 'proprietaire'))
            ->where('is_active', true)
            ->whereHas('vehicles', fn ($q) => $q->where('is_active', true)->whereDoesntHave('activeDriverContract'))
            ->with(['vehicles' => fn ($q) => $q->where('is_active', true)
                ->whereDoesntHave('activeDriverContract')
                ->with('activeVehicleContract')])
            ->orderBy('name')
            ->get(['id', 'name', 'phone']);

        return $owners->map(fn (User $owner) => AdminOwnerOptionData::fromModel($owner))->all();
    }
}
