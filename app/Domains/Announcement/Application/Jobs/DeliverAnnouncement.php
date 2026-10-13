<?php

namespace App\Domains\Announcement\Application\Jobs;

use App\Domains\Announcement\Application\Mail\AnnouncementMail;
use App\Domains\Announcement\Domain\AnnouncementStatus;
use App\Domains\Notification\Application\Data\NotificationPreferencesData;
use App\Domains\Notification\Application\Notifier;
use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use Carbon\CarbonInterface;
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
 * ⚠️ REJOUABLE sans doublon (relecture finale du 2026-10-13). Le worker tue un job qui
 * dépasse son délai, et la file le rejoue : chaque étape remise à un destinataire est donc
 * marquée sur sa ligne, et sautée si elle l'est déjà. Les paquets sont petits (5) et le délai
 * du job (80 s) reste sous le `retry_after` de la file (90 s) : un paquet d'e-mails avec un
 * règlement en pièce jointe tient dans ce délai, et un paquet tué reprend où il s'était arrêté.
 *
 * Un e-mail qui échoue est marqué `failed` et va au journal, sans arrêter les suivants. Un
 * communiqué retiré avant la remise ne part plus ; une relance saute ceux qui l'ont lu.
 */
final class DeliverAnnouncement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CHUNK = 5;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 80;

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

        // La relance remise est celle qui porte cette date ; une relance suivante en aura une autre.
        $reminderAt = $announcement->last_reminded_at;
        if ($this->reminder && ! $reminderAt) {
            return;
        }

        AnnouncementRecipient::query()
            ->with('user')
            ->where('announcement_id', $announcement->id)
            ->whereIn('id', $this->recipientIds)
            ->get()
            ->each(function (AnnouncementRecipient $recipient) use ($announcement, $notifier, $reminderAt) {
                if (! $recipient->user || ($this->reminder && $recipient->read_at !== null)) {
                    return;
                }

                $this->reminder
                    ? $this->remind($announcement, $recipient, $notifier, $reminderAt)
                    : $this->publish($announcement, $recipient, $notifier);
            });
    }

    private function publish(Announcement $announcement, AnnouncementRecipient $recipient, Notifier $notifier): void
    {
        if ($recipient->notified_at === null) {
            $notifier->announcementPublished($announcement, $recipient->user);
            $recipient->update(['notified_at' => now()]);
        }

        if ($recipient->emailed_at === null && $recipient->email_skipped === null) {
            $recipient->update($this->email($announcement, $recipient, 'emailed_at', now()));
        }
    }

    private function remind(Announcement $announcement, AnnouncementRecipient $recipient, Notifier $notifier, CarbonInterface $reminderAt): void
    {
        if (! $recipient->reminder_notified_at?->eq($reminderAt)) {
            $notifier->announcementReminder($announcement, $recipient->user);
            $recipient->update([
                'reminder_notified_at' => $reminderAt,
                'reminded_count' => $recipient->reminded_count + 1,
            ]);
        }

        if (! $recipient->reminder_emailed_at?->eq($reminderAt)) {
            $marks = $this->email($announcement, $recipient, 'emailed_at', now());
            $recipient->update([...$marks, 'reminder_emailed_at' => $reminderAt]);
        }
    }

    /**
     * Envoie l'e-mail s'il le faut, et rend les marques à poser sur la ligne.
     *
     * @return array<string, mixed>
     */
    private function email(Announcement $announcement, AnnouncementRecipient $recipient, string $sentColumn, CarbonInterface $now): array
    {
        $user = $recipient->user;

        if (! $user->email) {
            return ['email_skipped' => 'no_address'];
        }
        if (! NotificationPreferencesData::fromUser($user)->emailNotifications) {
            return ['email_skipped' => 'opted_out'];
        }

        try {
            // `send` et non `queue` : on est déjà dans la file, et la marque doit dire vrai.
            Mail::to($user->email)->send(new AnnouncementMail($announcement, $this->reminder));

            return [$sentColumn => $now, 'email_skipped' => null];
        } catch (\Throwable $e) {
            Log::warning('Communiqué : e-mail non parti', [
                'announcement_id' => $announcement->id,
                'user_id' => $user->id,
                'erreur' => $e->getMessage(),
            ]);

            return ['email_skipped' => 'failed'];
        }
    }
}
