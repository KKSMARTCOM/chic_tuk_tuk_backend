<?php

namespace App\Domains\Announcement\Application\Data;

use App\Shared\Data\BaseData;
use App\Shared\Data\PaginationData;

/** GET /admin/announcements/{id}/recipients — une page du suivi. */
final class AnnouncementRecipientPageData extends BaseData
{
    public function __construct(
        /** @var array<int, AnnouncementRecipientData> */
        public array $recipients,
        public PaginationData $pagination,
    ) {}
}
