<?php

namespace App\Domains\Announcement\Application\Actions;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Qui reçoit un communiqué (spec 2026-10-13, §5.1).
 *
 * UNE seule règle, pour l'aperçu de l'éditeur ET pour l'envoi : le décompte affiché est
 * celui qui part. Le profil se lit sur `users.profil`, comme le `Notifier` le fait pour les
 * administrateurs — jamais sur les rôles, dont les noms diffèrent entre environnements.
 */
final class ResolveAnnouncementRecipients
{
    public const PROFILES = ['admin', 'driver', 'owner', 'client'];

    /**
     * @param  list<string>  $profiles
     * @param  list<string>  $userIds
     * @return Collection<int, User>
     */
    public function __invoke(array $profiles, array $userIds, ?User $sender): Collection
    {
        $profiles = array_values(array_intersect($profiles, self::PROFILES));
        $userIds = array_values(array_filter($userIds, fn ($id) => is_string($id) && $id !== ''));

        if ($profiles === [] && $userIds === []) {
            return collect();
        }

        return User::query()
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q
                ->whereIn('profil', $profiles)
                ->orWhereIn('id', $userIds))
            ->when($sender, fn (Builder $q) => $q->whereKeyNot($sender->id))
            ->orderBy('name')
            ->get();
    }
}
