<?php

namespace App\Domains\Announcement\Application\Data;

use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/** Une ligne de la liste admin des communiqués. */
final class AdminAnnouncementListItemData extends BaseData
{
    public function __construct(
        public string $id,
        public string $title,
        #[LiteralTypeScriptType("'draft' | 'sent' | 'withdrawn'")]
        public string $status,
        public string $statusLabel,
        public ?string $createdByName,
        public ?string $sentAt,
        public int $recipientsCount,
        public int $readCount,
    ) {}
}
