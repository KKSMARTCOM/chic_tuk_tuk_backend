<?php

namespace App\Domains\Announcement\Application\Actions;

use App\Domains\Announcement\Application\Data\AnnouncementUserData;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Les comptes actifs dont le nom, l'e-mail ou le téléphone contient la saisie — 20 au plus. */
final class SearchAnnouncementUsers
{
    /** @return list<AnnouncementUserData> */
    public function __invoke(string $term): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return User::query()
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like)->orWhere('phone', 'like', $like))
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (User $u) => AnnouncementUserData::fromUser($u))
            ->all();
    }
}
