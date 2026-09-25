<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\AdminAvailableVehicleData;
use App\Models\Vehicle;

/**
 * Les véhicules qu'on peut rattacher à un propriétaire — ex-`$availableVehicles` de
 * Admin\OwnerController::create() et edit().
 *
 * Actif et sans contrat propriétaire-véhicule en cours, comme le Blade. Deux écarts :
 *
 *  - le Blade d'édition ajoutait les véhicules du propriétaire lui-même par un `orWhere`
 *    non groupé — y compris ceux sous contrat. Ils figurent déjà sur ses cartes : les
 *    reproposer ne menait qu'à un rattachement à soi-même. Ils sont exclus ;
 *  - le propriétaire actuel est nommé, parce qu'un rattachement vaut alors transfert et
 *    que l'administrateur doit le confirmer.
 *
 * @return array<int, AdminAvailableVehicleData>
 */
final class ListAvailableVehicles
{
    public function __invoke(?string $excludedOwnerId = null): array
    {
        return Vehicle::query()
            ->with('owner')
            ->where('is_active', true)
            ->whereDoesntHave('activeVehicleContract')
            ->when($excludedOwnerId, fn ($query) => $query->where(
                fn ($q) => $q->whereNull('owner_id')->orWhere('owner_id', '!=', $excludedOwnerId)
            ))
            ->orderBy('vehicle_number')
            ->get()
            ->map(fn (Vehicle $vehicle) => AdminAvailableVehicleData::fromModel($vehicle))
            ->all();
    }
}
