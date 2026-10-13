<?php

namespace App\Domains\Announcement\Application\Data;

use App\Shared\Data\BaseData;

/** Une ligne de « Communiqués », côté destinataire. */
final class MyAnnouncementListItemData extends BaseData
{
    public function __construct(
        public string $id,
        public string $title,
        public string $sentAt,
        public bool $isRead,
    ) {}
}
