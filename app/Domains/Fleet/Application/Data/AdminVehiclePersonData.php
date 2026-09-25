<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;

/**
 * Une personne rattachée à un véhicule, réduite à ce que la liste affiche.
 *
 * ⚠️ Pour un agent, `id` est l'identifiant de l'AGENT (`drivers.id`) — celui que prennent
 * les routes `/admin/drivers/{driver}` — et non celui de son compte.
 */
final class AdminVehiclePersonData extends BaseData
{
    public function __construct(
        public string $id,
        public ?string $name,
        public ?string $phone,
    ) {}
}
