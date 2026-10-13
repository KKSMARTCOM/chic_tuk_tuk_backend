<?php

namespace App\Domains\Announcement\Application\Data;

use App\Shared\Data\BaseData;

/** Un communiqué ouvert par son destinataire. `body_html` est sûr : voir `AnnouncementBody`. */
final class MyAnnouncementData extends BaseData
{
    public function __construct(
        public string $id,
        public string $title,
        public string $bodyHtml,
        public string $sentAt,
        public ?string $readAt,
        /** @var array<int, AnnouncementAttachmentData> */
        public array $attachments,
    ) {}
}
