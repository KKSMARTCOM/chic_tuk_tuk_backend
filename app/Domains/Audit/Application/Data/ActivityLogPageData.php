<?php

namespace App\Domains\Audit\Application\Data;

use App\Shared\Data\BaseData;
use App\Shared\Data\PaginationData;

/** GET /admin/activity-log — une page du journal, et le catalogue des événements filtrables. */
final class ActivityLogPageData extends BaseData
{
    public function __construct(
        /** @var ActivityEntryData[] */
        public array $entries,
        public PaginationData $pagination,
        /** @var ActivityEventOptionData[] */
        public array $events,
    ) {}
}
