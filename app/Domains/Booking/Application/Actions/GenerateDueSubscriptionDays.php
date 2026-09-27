<?php

namespace App\Domains\Booking\Application\Actions;

use App\Models\Booking;

/**
 * Génère les journées dues de tous les abonnements (commande de 1 h) — ex-
 * `BookingService::createRecurringBookings()`, déplacé sans changement le 2026-09-27.
 */
final class GenerateDueSubscriptionDays
{
    public function __construct(private readonly GenerateNextSubscriptionDay $generateNextDay) {}

    public function __invoke()
    {
        $recurringBookings = Booking::where('is_recurring', true)
            ->whereIn('status', ['confirmed', 'in_progress', 'completed'])
            ->where(function ($query) {
                // ⚠️ > 1 et non > 0 : `remaining_days` inclut la journée en cours
                // (`days` à la création, « Dernier jour » à 1). Générer jusqu'à 0 donnait
                // N+1 journées pour un abonnement de N jours.
                $query->where('remaining_days', '>', 1)
                    ->orWhere('makeup_go_count', '>', 0)
                    ->orWhere('makeup_return_count', '>', 0);
            })
            ->where('trip_type', 'go')
            ->whereNull('parent_booking_id')
            ->where(function ($query) {
                $query->whereNull('next_recurring_date')
                    ->orWhere('next_recurring_date', '<=', now());
            })
            ->get();

        foreach ($recurringBookings as $booking) {
            ($this->generateNextDay)($booking->id);
        }

        return $recurringBookings->count();
    }
}
