<?php

namespace App\Domains\Booking\Application\Actions;

use App\Domains\Booking\Domain\BookingLifecycle;
use App\Models\Booking;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Résilier un abonnement dont la course du premier jour est déjà faite ou non traitée
 * (2026-09-27).
 *
 * ⚠️ Le parent EST la course du premier jour. Le Blade le passait à « Annulée », et ce
 * jour — conduit, commissionné — sortait du revenu d'abonnement de l'agent, calculé sur
 * les courses terminées. Ici, on arrête l'abonnement SANS réécrire ce qui a eu lieu :
 *
 *  - plus de jours à générer ni de trajets à rattraper — c'est ce que lisent le cron de
 *    génération et la commande de rattrapage ;
 *  - les courses à venir (en attente ou acceptées) sont annulées, avec le motif ;
 *  - ce qui est en cours ou fait ne bouge pas.
 *
 * Un parent encore en attente ou accepté se résilie, lui, par le changement de statut
 * ordinaire (`ChangeBookingStatus`), qui annule tout : rien n'a encore été conduit.
 */
final class TerminateSubscription
{
    public function __invoke(Booking $parent, ?string $reason = null): Booking
    {
        if (! BookingLifecycle::canTerminateSubscription($parent)) {
            throw new ApiException(409, 'SUBSCRIPTION_NOT_RUNNING',
                "Cet abonnement n'a plus de course à venir : il n'y a rien à résilier.");
        }

        return DB::transaction(function () use ($parent, $reason) {
            $parent->update([
                'remaining_days' => 0,
                'makeup_go_count' => 0,
                'makeup_return_count' => 0,
            ]);

            Booking::where('parent_booking_id', $parent->id)
                ->whereIn('status', ['pending', 'confirmed'])
                ->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'cancellation_reason' => $reason ?: 'Abonnement résilié',
                ]);

            return $parent->refresh();
        });
    }
}
