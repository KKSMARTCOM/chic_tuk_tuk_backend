<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;

/** Un agent qu'on peut affecter en interne : actif, sans contrat ni affectation en cours (2026-10-09). */
final class AssignableDriverData extends BaseData
{
    public function __construct(
        public string $id,
        public ?string $name,
        public ?string $phone,
    ) {}
}
