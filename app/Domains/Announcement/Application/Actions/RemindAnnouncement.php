<?php

namespace App\Domains\Announcement\Application\Actions;

use App\Domains\Announcement\Application\Jobs\DeliverAnnouncement;
use App\Domains\Announcement\Domain\AnnouncementStatus;
use App\Models\Announcement;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Relancer les non-lus (spec 2026-10-13, §5.3). Une heure au moins entre deux relances :
 * contre le double clic, et contre le harcèlement involontaire.
 */
final class RemindAnnouncement
{
    public function __invoke(Announcement $announcement): int
    {
        [$announcement, $ids] = DB::transaction(function () use ($announcement) {
            $locked = Announcement::query()->lockForUpdate()->findOrFail($announcement->id);

            if ($locked->status !== AnnouncementStatus::Sent) {
                throw new ApiException(409, 'ANNOUNCEMENT_NOT_SENT', 'Seul un communiqué envoyé se relance.');
            }
            if ($locked->last_reminded_at && $locked->last_reminded_at->gt(now()->subHour())) {
                throw new ApiException(409, 'ANNOUNCEMENT_REMINDED_RECENTLY',
                    'Une relance a été faite à '.$locked->last_reminded_at->copy()->timezone('Africa/Porto-Novo')->format('H:i').' : attendez une heure entre deux relances.');
            }

            $ids = $locked->recipients()->whereNull('read_at')->pluck('id');
            if ($ids->isEmpty()) {
                throw new ApiException(409, 'ANNOUNCEMENT_ALL_READ', 'Tous les destinataires ont déjà lu ce communiqué.');
            }

            // `reminded_count` est compté par le job, à la remise réelle : un destinataire qui
            // lit entre-temps est sauté, et ne doit pas apparaître relancé.
            $locked->update(['last_reminded_at' => now()]);

            return [$locked, $ids];
        });

        DeliverAnnouncement::dispatchFor($announcement, $ids, true);

        return $ids->count();
    }
}
