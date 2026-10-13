<?php

namespace App\Domains\Announcement\Application\Data;

use App\Shared\Data\BaseData;
use App\Shared\Data\PaginationData;

/** GET /admin/announcements — une page de la liste. */
final class AdminAnnouncementPageData extends BaseData
{
    public function __construct(
        /** @var array<int, AdminAnnouncementListItemData> */
        public array $announcements,
        public PaginationData $pagination,
    ) {}
}
