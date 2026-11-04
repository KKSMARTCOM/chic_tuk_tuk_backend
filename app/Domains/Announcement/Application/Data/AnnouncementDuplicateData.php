<?php

namespace App\Domains\Announcement\Application\Data;

use App\Shared\Data\BaseData;

/** POST /admin/announcements/{id}/duplicate — le brouillon neuf, et les pièces non reprises. */
final class AnnouncementDuplicateData extends BaseData
{
    public function __construct(
        public AdminAnnouncementData $announcement,
        /** Une pièce dont le fichier a disparu du disque n'est pas copiée. */
        public int $skippedAttachments,
    ) {}
}
