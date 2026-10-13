<?php

namespace App\Domains\Announcement\Application\Data;

use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/** Une ligne du suivi de lecture. */
final class AnnouncementRecipientData extends BaseData
{
    public function __construct(
        public string $userId,
        public ?string $name,
        public string $profil,
        public ?string $readAt,
        public ?string $emailedAt,
        #[LiteralTypeScriptType("'no_address' | 'opted_out' | null")]
        public ?string $emailSkipped,
        public int $remindedCount,
    ) {}
}
