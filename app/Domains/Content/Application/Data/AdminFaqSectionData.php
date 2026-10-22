<?php

namespace App\Domains\Content\Application\Data;

use App\Shared\Data\BaseData;

final class AdminFaqSectionData extends BaseData
{
    public function __construct(
        public string $id,
        public string $title,
        /** @var AdminFaqEntryData[] */
        public array $entries,
    ) {}
}
