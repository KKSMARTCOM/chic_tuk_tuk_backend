<?php

namespace App\Domains\Announcement\Application\Data;

use App\Shared\Data\BaseData;
use App\Shared\Data\PaginationData;

/** GET /announcements — une page des communiqués reçus. */
final class MyAnnouncementPageData extends BaseData
{
    public function __construct(
        /** @var array<int, MyAnnouncementListItemData> */
        public array $announcements,
        public PaginationData $pagination,
    ) {}
}
