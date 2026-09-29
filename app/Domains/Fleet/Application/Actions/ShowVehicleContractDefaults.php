<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\ContractDurationData;
use App\Domains\Fleet\Application\Data\VehicleContractDefaultsData;
use App\Domains\Fleet\Domain\ContractTerms;
use App\Models\VehicleContractTerm;

/**
 * Ce qui préremplit un contrat propriétaire-véhicule, depuis les réglages de
 * l'administration (et non plus depuis des constantes, depuis le 2026-09-29).
 */
final class ShowVehicleContractDefaults
{
    public function __invoke(): VehicleContractDefaultsData
    {
        $charges = ContractTerms::chargeDefaults();

        return new VehicleContractDefaultsData(
            durations: VehicleContractTerm::query()->orderBy('months')->get()
                ->map(fn (VehicleContractTerm $term) => new ContractDurationData($term->months, (float) $term->total_amount))
                ->all(),
            unlimitedInternet: (float) $charges->unlimited_internet,
            spotifyPremium: (float) $charges->spotify_premium,
            managerRemuneration: (float) $charges->manager_remuneration,
        );
    }
}
