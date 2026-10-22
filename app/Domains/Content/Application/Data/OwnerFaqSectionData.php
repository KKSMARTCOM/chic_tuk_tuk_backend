<?php

namespace App\Domains\Content\Application\Data;

use App\Shared\Data\BaseData;

final class OwnerFaqSectionData extends BaseData
{
    public function __construct(
        public string $title,
        /** @var OwnerFaqEntryData[] */
        public array $entries,
    ) {}
}
