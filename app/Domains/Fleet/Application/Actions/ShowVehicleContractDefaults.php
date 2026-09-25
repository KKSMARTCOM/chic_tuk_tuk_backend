<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Consts\VehicleContractConsts;
use App\Domains\Fleet\Application\Data\ContractDurationData;
use App\Domains\Fleet\Application\Data\VehicleContractDefaultsData;

/** Les valeurs par défaut d'un contrat propriétaire-véhicule, depuis `VehicleContractConsts`. */
final class ShowVehicleContractDefaults
{
    public function __invoke(): VehicleContractDefaultsData
    {
        return new VehicleContractDefaultsData(
            durations: collect(VehicleContractConsts::TOTAL_AMOUNTS)
                ->map(fn ($amount, $months) => new ContractDurationData((int) $months, (float) $amount))
                ->values()
                ->all(),
            unlimitedInternet: VehicleContractConsts::DEFAULT_UNLIMITED_INTERNET,
            spotifyPremium: VehicleContractConsts::DEFAULT_SPOTIFY_PREMIUM,
            managerRemuneration: VehicleContractConsts::DEFAULT_MANAGER_REMUNERATION,
        );
    }
}
