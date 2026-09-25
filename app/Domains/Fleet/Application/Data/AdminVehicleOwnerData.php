<?php

namespace App\Domains\Fleet\Application\Data;

use App\Models\User;
use App\Shared\Data\BaseData;

/** Le propriétaire d'un véhicule, sur sa fiche. */
final class AdminVehicleOwnerData extends BaseData
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $phone,
        public ?string $email,
    ) {}

    public static function fromModel(User $owner): self
    {
        return new self($owner->id, $owner->name, $owner->phone, $owner->email);
    }
}
