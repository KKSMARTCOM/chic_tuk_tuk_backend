<?php

namespace App\Domains\Notification\Application\Actions;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Marquer une notification comme lue.
 *
 * ⚠️ La recherche est bornée à l'utilisateur AVANT le `findOrFail` : chercher d'abord
 * puis comparer le propriétaire répondrait 403 sur la notification d'autrui, ce qui
 * confirmerait son existence. Ici, celle d'un autre est simplement introuvable.
 */
final class MarkNotificationRead
{
    public function __invoke(User $user, int $id): void
    {
        /** @var Notification $notification */
        $notification = Notification::forUser($user->id)->findOrFail($id);

        // Pas de réécriture inutile : `updated_at` bougerait à chaque ouverture de la
        // cloche, alors que rien n'a changé.
        if (! $notification->is_read) {
            $notification->update(['is_read' => true]);
        }
    }

    /** @throws ModelNotFoundException */
    public function tout(User $user): void
    {
        Notification::forUser($user->id)->unread()->update([
            'is_read' => true,
            'updated_at' => now(),
        ]);
    }
}
