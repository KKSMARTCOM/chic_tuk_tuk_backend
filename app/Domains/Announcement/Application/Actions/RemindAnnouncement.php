<?php

namespace App\Domains\Announcement\Application\Actions;

use App\Domains\Announcement\Application\Jobs\DeliverAnnouncement;
use App\Domains\Announcement\Domain\AnnouncementStatus;
use App\Models\Announcement;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Relancer les non-lus (spec 2026-10-13, §5.3), ou une sélection, lus compris (2026-11-04).
 * Une heure au moins entre deux relances du même communiqué : contre le double clic, et
 * contre le harcèlement involontaire.
 */
final class RemindAnnouncement
{
    /**
     * @param  list<string>|null  $recipientIds  les lignes cochées ; `null` = les non-lus
     * @return int le nombre de lignes envoyées en remise
     */
    public function __invoke(Announcement $announcement, ?array $recipientIds = null, ?string $note = null): int
    {
        $selection = $recipientIds !== null;
        $note = $note !== null && trim($note) !== '' ? trim($note) : null;

        [$announcement, $ids] = DB::transaction(function () use ($announcement, $recipientIds, $selection, $note) {
            $locked = Announcement::query()->lockForUpdate()->findOrFail($announcement->id);

            if ($locked->status !== AnnouncementStatus::Sent) {
                throw new ApiException(409, 'ANNOUNCEMENT_NOT_SENT', 'Seul un communiqué envoyé se relance.');
            }
            if ($locked->last_reminded_at && $locked->last_reminded_at->gt(now()->subHour())) {
                throw new ApiException(409, 'ANNOUNCEMENT_REMINDED_RECENTLY',
                    'Une relance a été faite à '.$locked->last_reminded_at->copy()->timezone('Africa/Porto-Novo')->format('H:i').' : attendez une heure entre deux relances.');
            }

            $ids = $selection ? $this->selected($locked, $recipientIds) : $this->unread($locked);

            // `reminded_count` est compté par le job, à la remise réelle : un destinataire qui
            // lit entre-temps est sauté par la relance des non-lus, et ne doit pas apparaître relancé.
            $locked->update(['last_reminded_at' => now(), 'last_reminder_note' => $note]);

            return [$locked, $ids];
        });

        DeliverAnnouncement::dispatchFor($announcement, $ids, true, includeRead: $selection);

        return $ids->count();
    }

    /** @return Collection<int, string> */
    private function unread(Announcement $announcement): Collection
    {
        $unread = $announcement->recipients()->whereNull('read_at');
        if (! $unread->exists()) {
            throw new ApiException(409, 'ANNOUNCEMENT_ALL_READ', 'Tous les destinataires ont déjà lu ce communiqué.');
        }

        // Un compte désactivé depuis l'envoi n'est plus relancé (2026-10-14) ; un destinataire
        // AJOUTÉ après l'envoi depuis moins d'une heure vient de le recevoir (2026-11-04).
        $ids = $unread->whereHas('user', fn ($q) => $q->where('is_active', true))
            ->where(fn ($q) => $q->where('created_at', '<=', now()->subHour())->orWhere('created_at', '<=', $announcement->sent_at))
            ->pluck('id');
        if ($ids->isEmpty()) {
            throw new ApiException(409, 'ANNOUNCEMENT_NOTHING_TO_REMIND', 'Les destinataires qui n\'ont pas lu ce communiqué n\'ont plus de compte actif, ou viennent d\'être ajoutés.');
        }

        return $ids;
    }

    /**
     * @param  list<string>  $recipientIds
     * @return Collection<int, string>
     */
    private function selected(Announcement $announcement, array $recipientIds): Collection
    {
        $rows = $announcement->recipients()->whereIn('id', $recipientIds)->with('user')->get();
        if ($rows->count() !== count(array_unique($recipientIds))) {
            throw ValidationException::withMessages(['recipient_ids' => 'Un destinataire choisi n\'est pas sur ce communiqué.']);
        }

        $ids = $rows->filter(fn ($row) => $row->user?->is_active)->pluck('id')->values();
        if ($ids->isEmpty()) {
            throw new ApiException(409, 'ANNOUNCEMENT_NOTHING_TO_REMIND', 'Les destinataires choisis n\'ont plus de compte actif.');
        }

        return $ids;
    }
}
