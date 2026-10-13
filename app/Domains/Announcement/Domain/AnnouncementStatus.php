<?php

namespace App\Domains\Announcement\Domain;

/** Le cycle d'un communiqué (spec 2026-10-13, §4). Envoyé, il est figé ; retiré, c'est définitif. */
enum AnnouncementStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Sent => 'Envoyé',
            self::Withdrawn => 'Retiré',
        };
    }
}
