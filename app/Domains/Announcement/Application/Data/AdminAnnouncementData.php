<?php

namespace App\Domains\Announcement\Application\Data;

use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * Un communiqué, vu de l'administration : son contenu (Markdown pour l'éditeur, HTML pour
 * l'affichage), son ciblage, et le compteur de lecture.
 */
final class AdminAnnouncementData extends BaseData
{
    public function __construct(
        public string $id,
        public string $title,
        public string $body,
        public string $bodyHtml,
        #[LiteralTypeScriptType("'draft' | 'sent' | 'withdrawn'")]
        public string $status,
        public string $statusLabel,
        /** @var string[] */
        public array $targetProfiles,
        /** @var array<int, AnnouncementUserData> */
        public array $targetUsers,
        /** @var array<int, AnnouncementAttachmentData> */
        public array $attachments,
        public ?string $createdByName,
        public ?string $sentByName,
        public ?string $sentAt,
        public ?string $withdrawnAt,
        public ?string $lastRemindedAt,
        public int $recipientsCount,
        public int $readCount,
    ) {}
}
