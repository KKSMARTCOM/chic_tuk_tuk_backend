<?php

namespace App\Domains\Announcement\Application\Actions;

use App\Domains\Announcement\Domain\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\User;
use App\Shared\Http\ApiException;

/**
 * Retirer un communiqué (spec 2026-10-13, §4) : il disparaît chez les destinataires, reste
 * dans l'historique admin avec son suivi. Définitif.
 */
final class WithdrawAnnouncement
{
    public function __invoke(Announcement $announcement, User $by): Announcement
    {
        if ($announcement->status !== AnnouncementStatus::Sent) {
            throw new ApiException(409, 'ANNOUNCEMENT_NOT_SENT', 'Seul un communiqué envoyé se retire.');
        }

        $announcement->update([
            'status' => AnnouncementStatus::Withdrawn,
            'withdrawn_by' => $by->id,
            'withdrawn_at' => now(),
        ]);

        return $announcement;
    }
}
