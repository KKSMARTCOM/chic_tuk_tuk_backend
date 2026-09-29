<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\VehicleContractSettingsData;
use App\Domains\Fleet\Application\Data\VehicleContractTermData;
use App\Domains\Fleet\Domain\ContractTerms;
use App\Models\VehicleContractTerm;

/** Les réglages des contrats véhicule, durées rangées de la plus courte à la plus longue. */
final class ShowVehicleContractSettings
{
    public function __invoke(): VehicleContractSettingsData
    {
        $charges = ContractTerms::chargeDefaults();

        return new VehicleContractSettingsData(
            terms: VehicleContractTerm::query()->orderBy('months')->get()
                ->map(fn (VehicleContractTerm $term) => VehicleContractTermData::fromModel($term))
                ->all(),
            unlimitedInternet: (float) $charges->unlimited_internet,
            spotifyPremium: (float) $charges->spotify_premium,
            managerRemuneration: (float) $charges->manager_remuneration,
        );
    }
}
