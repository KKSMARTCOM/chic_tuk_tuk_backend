<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;

/**
 * GET /admin/vehicle-contracts/defaults — ce qui préremplit un contrat
 * propriétaire-véhicule : les durées proposées avec leur montant total, et les trois
 * charges mensuelles par défaut.
 *
 * Tiré de `VehicleContractConsts`, que le Blade lit aussi : une seule source. Ces valeurs
 * ne font que PRÉREMPLIR — l'administrateur peut les corriger, et l'API ne les impose
 * pas à l'enregistrement.
 */
final class VehicleContractDefaultsData extends BaseData
{
    public function __construct(
        /** @var array<int, ContractDurationData> */
        public array $durations,
        public float $unlimitedInternet,
        public float $spotifyPremium,
        public float $managerRemuneration,
    ) {}
}
