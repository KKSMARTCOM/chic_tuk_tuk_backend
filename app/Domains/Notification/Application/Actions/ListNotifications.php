<?php

namespace App\Domains\Notification\Application\Actions;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * La cloche d'un utilisateur : sa page de notifications, plus le compte des non lues.
 *
 * ⚠️ Le compte est calculé sur TOUTES ses non lues, pas sur la page demandée. La
 * pastille doit afficher 23 même quand la page n'en montre que quinze, sinon elle
 * changerait de valeur en tournant les pages.
 */
final class ListNotifications
{
    public const PAR_PAGE = 15;

    /** @return array{0: LengthAwarePaginator, 1: int} */
    public function __invoke(User $user, int $page = 1): array
    {
        $requete = Notification::forUser($user->id);

        return [
            (clone $requete)->orderByDesc('created_at')->orderByDesc('id')
                ->paginate(perPage: self::PAR_PAGE, page: $page),
            (clone $requete)->unread()->count(),
        ];
    }
}
