<?php

namespace App\Domains\Finance\Application\Actions;

use App\Models\Booking;
use App\Models\Payment;

/**
 * Le revenu d'abonnement d'un agent — ex-`CommissionService::getDriverSubscriptionRevenue()`,
 * déplacé sans changement le 2026-09-27.
 */
final class ComputeDriverSubscriptionRevenue
{
    /**
     * Revenu d'un agent sur les courses d'abonnement (mère ou enfants) qu'il a
     * terminées, réparti par abonnement.
     *
     * Un abonnement paie l'agence directement — pas l'agent au comptant, contrairement
     * à une course simple — donc ce que l'agent a gagné dessus n'est ni une commission
     * ni un dû qu'il reverse : c'est une somme que l'agence lui DOIT, réglée par les
     * paiements de type `subscription_revenue`.
     *
     * Le filtrage passe par `is_subscription_parent`/`is_subscription_child`, les mêmes
     * accessors que le reste du domaine (voir `Booking`) : un simple aller-retour porte
     * lui aussi un `parent_booking_id`, mais son parent n'est pas récurrent, et ces deux
     * accessors sont ce qui l'exclut correctement.
     */
    public function __invoke(string $driverId): array
    {
        $bookings = Booking::where('driver_id', $driverId)
            ->where('status', 'completed')
            ->where(function ($query) {
                $query->where('is_recurring', true)->orWhereNotNull('parent_booking_id');
            })
            ->with('parentBooking')
            ->get()
            ->filter(fn ($booking) => $booking->is_subscription_parent || $booking->is_subscription_child);

        $subscriptions = $bookings
            ->groupBy(fn ($booking) => $booking->is_subscription_parent ? $booking->id : $booking->parent_booking_id)
            ->map(function ($group) {
                $reference = $group->first()->is_subscription_parent
                    ? $group->first()
                    : $group->first()->parentBooking;

                return [
                    'subscription_id' => $reference->id,
                    'booking_number' => $reference->booking_number,
                    'bookings_count' => $group->count(),
                    'amount' => (float) $group->sum('driver_earning'),
                ];
            })
            ->values();

        $totalDue = (float) $subscriptions->sum('amount');
        $totalPaid = (float) Payment::where('driver_id', $driverId)
            ->where('payment_type', 'subscription_revenue')
            ->where('status', 'completed')
            ->sum('amount');

        return [
            'subscriptions' => $subscriptions,
            'total_due' => $totalDue,
            'total_paid' => $totalPaid,
            'balance_due' => $totalDue - $totalPaid,
        ];
    }
}
