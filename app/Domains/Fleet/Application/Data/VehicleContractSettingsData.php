<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;

/**
 * GET /admin/settings/vehicle-contracts — les durées proposées avec leurs montants, et
 * les trois charges mensuelles par défaut.
 *
 * ⚠️ Un contrat COPIE ces montants à sa création : les modifier ne touche aucun contrat
 * existant.
 */
final class VehicleContractSettingsData extends BaseData
{
    public function __construct(
        /** @var VehicleContractTermData[] */
        public array $terms,
        public float $unlimitedInternet,
        public float $spotifyPremium,
        public float $managerRemuneration,
    ) {}
}
