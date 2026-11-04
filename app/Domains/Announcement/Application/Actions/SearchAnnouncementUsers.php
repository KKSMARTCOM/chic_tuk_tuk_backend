<?php

namespace App\Domains\Announcement\Application\Actions;

use App\Domains\Announcement\Application\Data\AnnouncementUserData;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use App\Models\AnnouncementRecipient;
use Illuminate\Support\Str;

/** Les comptes actifs dont le nom, l'e-mail ou le téléphone contient la saisie — 20 au plus. */
final class SearchAnnouncementUsers
{
    /** @return list<AnnouncementUserData> */
    public function __invoke(string $term, ?string $announcementId = null): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }
        // Pour l'ajout à un communiqué envoyé (2026-11-04) : ses destinataires sont signalés.
        $recipients = $announcementId && Str::isUuid($announcementId)
            ? AnnouncementRecipient::where('announcement_id', $announcementId)->pluck('user_id')->all()
            : [];
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return User::query()
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like)->orWhere('phone', 'like', $like))
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(function (User $u) use ($recipients) {
                $data = AnnouncementUserData::fromUser($u);
                $data->isRecipient = in_array($u->id, $recipients, true);

                return $data;
            })
            ->all();
    }
}
