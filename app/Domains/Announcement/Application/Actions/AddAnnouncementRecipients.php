<?php

namespace App\Domains\Announcement\Application\Actions;

use App\Domains\Announcement\Application\Jobs\DeliverAnnouncement;
use App\Domains\Announcement\Domain\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\User;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ajouter des destinataires à un communiqué envoyé (spec 2026-11-04-communiques-relance-ajout).
 * Ils reçoivent l'envoi d'origine ; les personnes ajoutées une à une entrent dans le ciblage.
 * Un destinataire existant, un compte inactif ou l'expéditeur sont ignorés sans erreur.
 */
final class AddAnnouncementRecipients
{
    public function __construct(private readonly PreviewNewAnnouncementRecipients $newcomers) {}

    /**
     * @param  list<string>  $userIds
     * @return int le nombre de destinataires ajoutés
     */
    public function __invoke(Announcement $announcement, array $userIds, bool $newFromTargeting): int
    {
        [$announcement, $rowIds] = DB::transaction(function () use ($announcement, $userIds, $newFromTargeting) {
            $locked = Announcement::query()->lockForUpdate()->findOrFail($announcement->id);
            if ($locked->status !== AnnouncementStatus::Sent) {
                throw new ApiException(409, 'ANNOUNCEMENT_NOT_SENT', 'Seul un communiqué envoyé reçoit de nouveaux destinataires.');
            }

            $existing = $locked->recipients()->pluck('user_id')->all();
            $users = $newFromTargeting
                ? ($this->newcomers)($locked)
                : User::query()->whereIn('id', array_values(array_unique($userIds)))->where('is_active', true)
                    ->whereKeyNot($locked->sent_by)->whereNotIn('id', $existing)->orderBy('name')->get();

            if ($users->isEmpty()) {
                throw ValidationException::withMessages(['user_ids' => 'Personne à ajouter : les comptes choisis sont déjà destinataires, désactivés, ou l’expéditeur.']);
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
            DB::table('announcement_recipients')->insert($rows->values()->all());

            if (! $newFromTargeting) {
                $locked->update(['target_user_ids' => array_values(array_unique([...($locked->target_user_ids ?? []), ...$users->pluck('id')->all()]))]);
            }

            return [$locked, $rows->pluck('id')];
        });

        DeliverAnnouncement::dispatchFor($announcement, $rowIds, false);

        return $rowIds->count();
    }
}
