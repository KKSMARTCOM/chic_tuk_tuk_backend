<?php

namespace App\Domains\Announcement\Application\Jobs;

use App\Domains\Announcement\Application\Mail\AnnouncementMail;
use App\Domains\Announcement\Domain\AnnouncementStatus;
use App\Domains\Notification\Application\Data\NotificationPreferencesData;
use App\Domains\Notification\Application\Notifier;
use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Remet un communiqué à un paquet de destinataires : cloche + push, puis e-mail (spec
 * 2026-10-13, §5.2 et §5.3).
 *
 * Par paquets de 50 : un échec ne touche qu'un paquet. Chaque destinataire est traité à part,
 * et un e-mail qui échoue va au journal sans arrêter les suivants. Un communiqué retiré entre
 * l'envoi et la remise ne part plus ; une relance saute ceux qui l'ont lu entre-temps.
 */
final class DeliverAnnouncement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CHUNK = 50;

    public int $tries = 3;

    public int $backoff = 60;

    /** @param  list<string>  $recipientIds */
    public function __construct(
        public readonly string $announcementId,
        public readonly array $recipientIds,
        public readonly bool $reminder = false,
    ) {}

    /** @param  Collection<int, string>|list<string>  $recipientIds */
    public static function dispatchFor(Announcement $announcement, Collection|array $recipientIds, bool $reminder): void
    {
        collect($recipientIds)->chunk(self::CHUNK)->each(
            fn (Collection $chunk) => self::dispatch($announcement->id, $chunk->values()->all(), $reminder)
        );
    }

    public function handle(Notifier $notifier): void
    {
        $announcement = Announcement::with('attachments')->find($this->announcementId);
        if (! $announcement || $announcement->status !== AnnouncementStatus::Sent) {
            return;
        }

        AnnouncementRecipient::query()
            ->with('user')
            ->where('announcement_id', $announcement->id)
            ->whereIn('id', $this->recipientIds)
            ->get()
            ->each(function (AnnouncementRecipient $recipient) use ($announcement, $notifier) {
                $user = $recipient->user;
                if (! $user || ($this->reminder && $recipient->read_at !== null)) {
                    return;
                }

                $this->reminder
                    ? $notifier->announcementReminder($announcement, $user)
                    : $notifier->announcementPublished($announcement, $user);

                $this->email($announcement, $recipient);
            });
    }

    private function email(Announcement $announcement, AnnouncementRecipient $recipient): void
    {
        $user = $recipient->user;

        if (! $user->email) {
            $recipient->update(['email_skipped' => 'no_address']);

            return;
        }
        if (! NotificationPreferencesData::fromUser($user)->emailNotifications) {
            $recipient->update(['email_skipped' => 'opted_out']);

            return;
        }

        try {
            // `send` et non `queue` : on est déjà dans la file, et `emailed_at` doit dire vrai.
            Mail::to($user->email)->send(new AnnouncementMail($announcement, $this->reminder));
            $recipient->update(['emailed_at' => now(), 'email_skipped' => null]);
        } catch (\Throwable $e) {
            Log::warning('Communiqué : e-mail non parti', [
                'announcement_id' => $announcement->id,
                'user_id' => $user->id,
                'erreur' => $e->getMessage(),
            ]);
        }
    }
}
