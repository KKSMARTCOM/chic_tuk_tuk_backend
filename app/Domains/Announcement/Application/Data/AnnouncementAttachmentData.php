<?php

namespace App\Domains\Announcement\Application\Data;

use App\Models\AnnouncementAttachment;
use App\Shared\Data\BaseData;

/** Une pièce jointe : de quoi l'afficher et la télécharger, jamais son chemin sur le disque. */
final class AnnouncementAttachmentData extends BaseData
{
    public function __construct(
        public string $id,
        public string $originalName,
        public string $mimeType,
        public int $size,
    ) {}

    public static function fromModel(AnnouncementAttachment $file): self
    {
        return new self($file->id, $file->original_name, $file->mime_type, (int) $file->size);
    }
}
