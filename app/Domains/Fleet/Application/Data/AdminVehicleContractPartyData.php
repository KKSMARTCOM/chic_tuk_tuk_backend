<?php

namespace App\Domains\Fleet\Application\Data;

use App\Models\User;
use App\Shared\Data\BaseData;

/**
 * Le propriétaire ou l'agent d'un contrat véhicule, tel que la fiche l'affiche.
 *
 * ⚠️ Pour un agent, `id` est l'identifiant de l'AGENT (`drivers.id`), celui des routes
 * `/admin/drivers/{driver}`, et non celui de son compte.
 */
final class AdminVehicleContractPartyData extends BaseData
{
    public function __construct(
        public string $id,
        public ?string $name,
        public ?string $phone,
        public ?string $email,
    ) {}

    public static function fromUser(string $id, User $user): self
    {
        return new self($id, $user->name, $user->phone, $user->email);
    }
}
