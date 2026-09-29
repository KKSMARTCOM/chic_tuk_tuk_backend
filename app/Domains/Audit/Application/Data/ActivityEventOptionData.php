<?php

namespace App\Domains\Audit\Application\Data;

use App\Shared\Data\BaseData;

/** Un événement proposé au filtre de l'écran, avec son groupe. */
final class ActivityEventOptionData extends BaseData
{
    public function __construct(
        public string $value,
        public string $label,
        public string $group,
    ) {}
}
