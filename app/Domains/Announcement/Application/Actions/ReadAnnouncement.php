<?php

namespace App\Domains\Announcement\Application\Actions;

use App\Domains\Announcement\Domain\AnnouncementStatus;
use App\Models\AnnouncementRecipient;
use App\Models\Notification;
use App\Models\User;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Ouvrir un communiqué (spec 2026-10-13, §5.4).
 *
 * ⚠️ La recherche part de la LIGNE DESTINATAIRE de l'utilisateur : le communiqué d'autrui et
 * le brouillon sont introuvables (404), sans révéler qu'ils existent. Le retiré répond 410 :
 * le destinataire l'a reçu, il peut savoir qu'il a été retiré.
 *
 * `read_at` ne s'écrit qu'une fois (`whereNull`), même si deux onglets s'ouvrent ensemble.
 */
final class ReadAnnouncement
{
    public function __invoke(User $user, string $id): AnnouncementRecipient
    {
        $recipient = AnnouncementRecipient::query()
            ->with('announcement.attachments')
            ->where('user_id', $user->id)
            ->where('announcement_id', $id)
            ->first();

        if (! $recipient || $recipient->announcement->status === AnnouncementStatus::Draft) {
            throw (new ModelNotFoundException)->setModel(AnnouncementRecipient::class);
        }
        if ($recipient->announcement->status === AnnouncementStatus::Withdrawn) {
            throw new ApiException(410, 'ANNOUNCEMENT_WITHDRAWN', 'Ce communiqué a été retiré.');
        }

        if ($recipient->read_at === null) {
            AnnouncementRecipient::whereKey($recipient->id)->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);
            $recipient->refresh()->load('announcement.attachments');
        }

        // La cloche suit : la notification d'envoi et celles des relances.
        Notification::forUser($user->id)->unread()
            ->where('data->announcement_id', $id)
            ->update(['is_read' => true, 'updated_at' => now()]);

        return $recipient;
    }
}
