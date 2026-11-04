<?php

namespace App\Domains\Announcement\Application\Data;

use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/** Une ligne du suivi de lecture. */
final class AnnouncementRecipientData extends BaseData
{
    public function __construct(
        /** La ligne, pour la relance d'une sélection (2026-11-04). */
        public string $id,
        public string $userId,
        public ?string $name,
        public string $profil,
        public ?string $readAt,
        public ?string $emailedAt,
        #[LiteralTypeScriptType("'no_address' | 'opted_out' | 'failed' | null")]
        public ?string $emailSkipped,
        public int $remindedCount,
        /** Un compte désactivé n'est plus relancé : la confirmation le compte à part. */
        public bool $isActive,
        /** Ajouté après l'envoi (2026-11-04) ; `null` pour l'envoi d'origine. */
        public ?string $addedAt,
    ) {}
}
