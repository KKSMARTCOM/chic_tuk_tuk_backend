<?php

namespace App\Domains\Announcement\Application\Actions;

use App\Domains\Announcement\Application\Data\UnreadAnnouncementsData;
use App\Domains\Announcement\Domain\AnnouncementStatus;
use App\Models\AnnouncementRecipient;
use App\Models\User;

/** Les non-lus d'un destinataire, retirés exclus — le badge du menu et le bandeau. */
final class CountUnreadAnnouncements
{
    public function __invoke(User $user): UnreadAnnouncementsData
    {
        $ids = AnnouncementRecipient::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->whereHas('announcement', fn ($q) => $q->where('status', AnnouncementStatus::Sent->value))
            ->pluck('announcement_id');

        return new UnreadAnnouncementsData($ids->count(), $ids->count() === 1 ? $ids->first() : null);
    }
}
