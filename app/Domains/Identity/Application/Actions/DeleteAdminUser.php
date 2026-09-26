<?php

namespace App\Domains\Identity\Application\Actions;

use App\Models\Booking;
use App\Models\Testimonial;
use App\Models\User;
use App\Shared\Http\ApiException;

/**
 * Supprimer un administrateur — ex-Admin\UserController::destroy().
 *
 * ⚠️ Un compte qui a enregistré des réservations ne se supprime pas (décidé le
 * 2026-09-26). Le Blade inscrit l'administrateur dans `bookings.user_id` quand il crée
 * une course, et la clé étrangère est en cascade : la suppression emportait ces
 * réservations. Même raisonnement que pour les propriétaires, même issue : désactiver.
 */
final class DeleteAdminUser
{
    public function __construct(private readonly AdminAccountRules $rules) {}

    public function __invoke(User $actor, User $user): void
    {
        $this->rules->ensureTargetWithinActor($actor, $user);

        if ($user->is($actor)) {
            throw new ApiException(409, 'USER_SELF_DELETION', 'Vous ne pouvez pas supprimer votre propre compte.');
        }

        $hasRecords = Booking::query()->where('user_id', $user->id)->exists()
            || Testimonial::query()->where('user_id', $user->id)->exists();

        if ($hasRecords) {
            throw new ApiException(409, 'USER_NOT_DELETABLE',
                'Impossible de supprimer cet administrateur : des réservations sont enregistrées à son nom. Désactivez son compte à la place.');
        }

        $user->delete();
    }
}
