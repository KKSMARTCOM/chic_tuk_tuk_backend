<?php

namespace App\Domains\Booking\Application\Actions;

use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Génère la journée suivante d'un abonnement — ex-`BookingService::generateNextRecurringDay()`,
 * déplacé sans changement le 2026-09-27.
 */
final class GenerateNextSubscriptionDay
{
    /**
     * Génère la journée suivante d'un abonnement si son heure est venue. Renvoie false
     * s'il n'y avait rien à générer.
     */
    public function __invoke(string $bookingId): bool
    {
        return DB::transaction(function () use ($bookingId) {
            // Recharger avec verrou
            $booking = Booking::lockForUpdate()->find($bookingId);

            if (
                ! $booking
                || ! $booking->is_subscription_parent
                || $booking->trip_type !== 'go'
                || ($booking->remaining_days <= 1 && $booking->makeup_go_count <= 0 && $booking->makeup_return_count <= 0)
                || ! in_array($booking->status, ['confirmed', 'in_progress', 'completed'])
                || ($booking->next_recurring_date && $booking->next_recurring_date->isFuture())
            ) {
                return false;
            }

            // Créer la nouvelle course pour le jour suivant
            $nextAllowedDay = $booking->next_recurring_date ? Carbon::parse($booking->next_recurring_date)->addDay()->startOfDay() : getNextAllowedDay(Carbon::parse($booking->pickup_date), $booking->week_days ?? 'lun_dim');
            if (! $nextAllowedDay) {
                return false;
            }

            $newPickupDate = $nextAllowedDay->copy()->setTimeFromTimeString($booking->pickup_time);

            // Jours normaux d'abord ; une fois épuisés, les trajets dus au client après une
            // course non traitée — et seulement ceux-là, sens par sens.
            $hasReturnLeg = $booking->round_trip && $booking->return_time;
            $isMakeup = $booking->remaining_days <= 1;
            $withGo = ! $isMakeup || $booking->makeup_go_count > 0;
            $withReturn = $hasReturnLeg && (! $isMakeup || $booking->makeup_return_count > 0);

            // Un rattrapage ne consomme pas de jour normal : il reste « dernier jour ».
            $newRemaining = $isMakeup ? $booking->remaining_days : $booking->remaining_days - 1;
            $goLeft = $booking->makeup_go_count - ($isMakeup && $withGo ? 1 : 0);
            // Sans heure de retour, un retour dû ne peut pas être généré : il ne doit pas
            // non plus faire tourner la commande indéfiniment.
            $returnLeft = $hasReturnLeg ? $booking->makeup_return_count - ($isMakeup && $withReturn ? 1 : 0) : 0;
            $moreToDo = $newRemaining > 1 || $goLeft > 0 || $returnLeft > 0;

            // Prochain passage du cron = jour suivant autorisé à 1h
            $nextAllowedForCron = getNextAllowedDay($nextAllowedDay, $booking->week_days ?? 'lun_dim');
            $nextRecurring = $moreToDo && $nextAllowedForCron ? $nextAllowedForCron->copy()->subDay()->setTime(1, 0) : null;

            $subDriverId = $booking->subscription_driver_id;

            // --- Course ALLER ---
            if ($withGo) {
                Booking::create([
                    'from_location' => $booking->from_location,
                    'to_location' => $booking->to_location,
                    'from_lng' => $booking->from_lng,
                    'from_lat' => $booking->from_lat,
                    'to_lng' => $booking->to_lng,
                    'to_lat' => $booking->to_lat,
                    'distance' => $booking->distance,
                    'phone' => $booking->phone,
                    'days' => $booking->days,
                    'remaining_days' => $newRemaining,
                    'week_days' => $booking->week_days,
                    'round_trip' => $booking->round_trip,
                    'return_time' => $booking->return_time,
                    'trip_type' => 'go',
                    'pickup_date' => $newPickupDate->toDateString(),
                    'pickup_time' => $newPickupDate->format('H:i'),
                    'special_requests' => $booking->special_requests,
                    'tourist_circuit_id' => $booking->tourist_circuit_id,
                    'promo_code_id' => $booking->promo_code_id,
                    'discount' => $booking->discount,
                    'base_price' => $booking->base_price,
                    'total_price' => $booking->total_price,
                    'status' => 'pending',
                    'is_recurring' => false,
                    'parent_booking_id' => $booking->id,
                    'subscription_driver_id' => $subDriverId,
                    'next_recurring_date' => null,
                    'client_name' => $booking->client_name,
                ]);
            }

            // --- Course RETOUR (si aller-retour) ---
            if ($withReturn) {
                $returnDate = $newPickupDate->copy()->setTimeFromTimeString($booking->return_time);

                Booking::create([
                    'from_location' => $booking->to_location,
                    'to_location' => $booking->from_location,
                    'from_lng' => $booking->to_lng,
                    'from_lat' => $booking->to_lat,
                    'to_lng' => $booking->from_lng,
                    'to_lat' => $booking->from_lat,
                    'distance' => $booking->distance,
                    'phone' => $booking->phone,
                    'days' => $booking->days,
                    'remaining_days' => $newRemaining,
                    'week_days' => $booking->week_days,
                    'round_trip' => true,
                    'return_time' => null,
                    'trip_type' => 'return',
                    'pickup_date' => $returnDate->toDateString(),
                    'pickup_time' => $returnDate->format('H:i'),
                    'special_requests' => $booking->special_requests,
                    'tourist_circuit_id' => $booking->tourist_circuit_id,
                    'promo_code_id' => $booking->promo_code_id,
                    'discount' => $booking->discount,
                    'base_price' => $booking->base_price,
                    'total_price' => $booking->total_price,
                    'status' => 'pending',
                    'is_recurring' => false,
                    'parent_booking_id' => $booking->id,
                    'subscription_driver_id' => $subDriverId,
                    'client_name' => $booking->client_name,
                ]);
            }

            // Mettre à jour la course actuelle avec le nombre de jours restants
            $endDate = $booking->subscription_end_date;
            $booking->update([
                'remaining_days' => $newRemaining,
                'makeup_go_count' => max(0, $goLeft),
                'makeup_return_count' => max(0, $returnLeft),
                'next_recurring_date' => $nextRecurring,
                // Un rattrapage repousse la fin de l'abonnement.
                'subscription_end_date' => $endDate && $endDate->greaterThan($newPickupDate) ? $endDate : $newPickupDate->toDateString(),
                // ⚠️ Ne PAS repasser `is_recurring` à false au dernier jour : le parent
                // cesserait d'être un abonnement, et ses enfants avec lui — ils sortaient du
                // récap des revenus, et les courses du dernier jour devenaient visibles de
                // tous. `remaining_days` à 0 suffit à arrêter la génération.
            ]);

            return true;
        });
    }
}
