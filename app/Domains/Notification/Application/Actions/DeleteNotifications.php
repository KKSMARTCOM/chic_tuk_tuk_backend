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

    /**
     * @return int le nombre de notifications lues que la personne VOYAIT ; les non lues restent.
     *             Les « course disponible » masquées et lues partent aussi, sans être comptées :
     *             annoncer « 42 supprimées » pour 5 visibles serait faux (relecture finale).
     */
    public function read(User $user): int
    {
        $visible = Notification::forUser($user->id)->visible()->where('is_read', true)->count();
        Notification::forUser($user->id)->where('is_read', true)->delete();

        return $visible;
    }
}
