<?php

namespace App\Domains\Notification\Application\Actions;

use App\Models\Notification;
use App\Models\User;

/**
 * Supprimer ses notifications (spec 2026-10-27-liste-des-notifications, §3.1) : une seule,
 * ou toutes celles qu'on a lues. La portée est le compte : celle d'un autre est un 404.
 */
final class DeleteNotifications
{
    public function one(User $user, int $id): void
    {
        Notification::forUser($user->id)->findOrFail($id)->delete();
    }

    /** @return int le nombre de notifications supprimées ; les non lues restent */
    public function read(User $user): int
    {
        return Notification::forUser($user->id)->where('is_read', true)->delete();
    }
}
