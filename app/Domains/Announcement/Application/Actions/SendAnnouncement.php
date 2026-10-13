<?php

namespace App\Domains\Announcement\Application\Actions;

use App\Domains\Announcement\Application\Jobs\DeliverAnnouncement;
use App\Domains\Announcement\Domain\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Envoyer un communiqué (spec 2026-10-13, §5.2).
 *
 * Dans la transaction, communiqué verrouillé : les destinataires sont calculés et figés, le
 * statut passe à `sent`. Deux clics simultanés : le second trouve `sent` et est refusé. La
 * remise part APRÈS le commit, en file : l'admin n'attend pas 40 e-mails.
 */
final class SendAnnouncement
{
    public function __construct(private readonly ResolveAnnouncementRecipients $resolve) {}

    public function __invoke(Announcement $announcement, User $sender): Announcement
    {
        [$announcement, $recipientIds] = DB::transaction(function () use ($announcement, $sender) {
            $locked = Announcement::query()->lockForUpdate()->findOrFail($announcement->id);
            SaveAnnouncementDraft::assertDraft($locked);

            if (trim($locked->title) === '' || trim((string) $locked->body) === '') {
                throw ValidationException::withMessages(['body' => 'Le titre et le texte sont obligatoires pour envoyer.']);
            }

            $users = ($this->resolve)($locked->target_profiles ?? [], $locked->target_user_ids ?? [], $sender);
            if ($users->isEmpty()) {
                throw ValidationException::withMessages(['target_profiles' => 'Aucun destinataire : cochez un profil ou ajoutez une personne.']);
            }

            $now = now();
            $rows = $users->map(fn (User $user) => [
                'id' => (string) Str::uuid(),
                'announcement_id' => $locked->id,
                'user_id' => $user->id,
                'profil' => (string) $user->profil,
                'reminded_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $rows->chunk(500)->each(fn ($chunk) => DB::table('announcement_recipients')->insert($chunk->values()->all()));

            $locked->update(['status' => AnnouncementStatus::Sent, 'sent_by' => $sender->id, 'sent_at' => $now]);

            return [$locked, $rows->pluck('id')];
        });

        DeliverAnnouncement::dispatchFor($announcement, $recipientIds, false);

        return $announcement;
    }
}
