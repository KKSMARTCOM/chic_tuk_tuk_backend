<?php

namespace App\Domains\Booking\Application\Actions;

use App\Models\Booking;
use Illuminate\Support\Collection;

/**
 * Les courses d'abonnement expirées dont l'abonnement court encore — ex-
 * `BookingService::expiredChildrenOfOngoingSubscriptions()`, déplacé sans changement le
 * 2026-09-27.
 */
final class ListMissedSubscriptionChildren
{
    /**
     * Reprise du passé : les courses enfants déjà marquées « expirée » des abonnements
     * ENCORE EN COURS passent « non traitée », et sont rattrapées. Les abonnements terminés
     * restent en l'état — décision de l'utilisateur.
     *
     * Un abonnement est en cours s'il lui reste des jours à générer, ou si sa dernière
     * course n'est pas encore passée.
     *
     * @return Collection<int, Booking> les courses concernées
     */
    public function __invoke(): Collection
    {
        $today = now()->toDateString();

        return Booking::with('parentBooking')
            ->where('status', 'expired')
            ->whereNotNull('parent_booking_id')
            ->whereHas('parentBooking', function ($parent) use ($today) {
                $parent->where('is_recurring', true)
                    ->whereNull('parent_booking_id')
                    ->whereIn('status', ['confirmed', 'in_progress', 'completed'])
                    ->where(function ($ongoing) use ($today) {
                        $ongoing->where('remaining_days', '>', 1)
                            ->orWhereHas('childBookings', fn ($c) => $c->whereDate('pickup_date', '>=', $today));
                    });
            })
            ->orderBy('pickup_date')
            ->get()
            ->filter(fn ($booking) => $booking->is_subscription_child)
            ->values();
    }
}
