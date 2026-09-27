<?php

namespace App\Domains\Booking\Application\Actions;

use App\Domains\Booking\Domain\PriceCalculator;
use App\Domains\Notification\Application\Notifier;
use App\Models\Booking;
use Carbon\Carbon;

/**
 * Créer une réservation — courses simples, aller-retour, abonnements. La seule
 * implémentation de la création, partagée par le tunnel public et l'administration.
 *
 * Ex-`BookingService::create()`, déplacé sans changement le 2026-09-27.
 */
final class CreateBooking
{
    public function __construct(
        private readonly PriceCalculator $pricingService,
        private readonly MeasureRouteDistance $measureDistance,
    ) {}

    public function __invoke(array $data)
    {
        // Calcul de la distance
        $distance = ($this->measureDistance)(
            $data['from_lng'],
            $data['from_lat'],
            $data['to_lng'],
            $data['to_lat']
        );

        if ($distance === null) {
            throw new \Exception('Erreur lors du calcul de l\'itinéraire.');
        }

        // Prix brut sans majoration
        $basePrice = $data['base_price'] ?? $this->pricingService->getPrice($distance);

        $isRecurring = isset($data['days']) && $data['days'] > 1;
        $isRound = ! empty($data['round_trip']);
        $days = $data['days'] ?? 1;

        // Prix de l'aller, avec majoration selon l'heure de départ
        $goPrice = $this->pricingService->applyTimeSurcharge($basePrice, $data['pickup_time']);

        // Prix du retour, avec majoration selon l'heure de retour (si aller-retour)
        $returnPrice = ($isRound && ! empty($data['return_time']))
            ? $this->pricingService->applyTimeSurcharge($basePrice, $data['return_time'])
            : $goPrice;

        $tripPrice = $isRound ? $goPrice + $returnPrice : $goPrice;
        $totalPrice = $tripPrice * ($isRecurring ? $days : 1);

        // Calcul de la date du prochain passage du cron pour les courses récurrentes
        $firstNextDay = getNextAllowedDay(Carbon::parse($data['pickup_date']), $data['week_days'] ?? 'lun_dim');
        $nextRecurringDate = $isRecurring && $firstNextDay ? $firstNextDay->copy()->subDay()->setTime(1, 0) : null;

        $endDate = null;
        if ($isRecurring && isset($data['week_days'])) {
            $endDate = calculateEndDate($data['pickup_date'], $days, $data['week_days']);
        }

        $booking = Booking::create([
            'user_id' => $data['user_id'] ?? null,
            'from_location' => $data['from_location'],
            'to_location' => $data['to_location'],
            'from_lng' => $data['from_lng'],
            'from_lat' => $data['from_lat'],
            'to_lng' => $data['to_lng'],
            'to_lat' => $data['to_lat'],
            'phone' => $data['phone'],
            'days' => $data['days'] ?? 1,
            'remaining_days' => $data['days'] ?? 1,
            'week_days' => $data['week_days'] ?? null,
            'round_trip' => ! empty($data['round_trip']) ? 1 : 0,
            'return_time' => $isRound ? ($data['return_time'] ?? null) : null,
            'trip_type' => 'go',
            'pickup_date' => $data['pickup_date'],
            'pickup_time' => $data['pickup_time'],
            'special_requests' => $data['special_requests'] ?? null,
            'distance' => $distance,
            'base_price' => $goPrice,
            'total_price' => $totalPrice,
            'is_recurring' => $isRecurring,
            'next_recurring_date' => $isRecurring ? $nextRecurringDate : null,
            'client_name' => ! empty($data['client_name']) ? $data['client_name'] : 'Client',
            'status' => $data['status'] ?? 'pending',
            'tourist_circuit_id' => $data['tourist_circuit_id'] ?? null,
            'subscription_end_date' => $endDate,
        ]);

        // Créer la course retour si aller-retour et non récurrent
        if (! $isRecurring && $isRound && ! empty($data['return_time'])) {
            Booking::create([
                'from_location' => $data['to_location'],
                'to_location' => $data['from_location'],
                'from_lng' => $data['to_lng'],
                'from_lat' => $data['to_lat'],
                'to_lng' => $data['from_lng'],
                'to_lat' => $data['from_lat'],
                'distance' => $distance,
                'phone' => $data['phone'],
                'days' => 1,
                'remaining_days' => 1,
                'week_days' => null,
                'round_trip' => true,
                'return_time' => null,
                'trip_type' => 'return',
                'pickup_date' => $data['pickup_date'],
                'pickup_time' => $data['return_time'],
                'special_requests' => $data['special_requests'] ?? null,
                'tourist_circuit_id' => $data['tourist_circuit_id'] ?? null,
                'promo_code_id' => $data['promo_code_id'] ?? null,
                'discount' => $data['discount'] ?? 0,
                'base_price' => $returnPrice,
                'total_price' => $tripPrice,
                'status' => 'pending',
                'is_recurring' => false,
                'parent_booking_id' => $booking->id,
                'subscription_driver_id' => null, // cachée jusqu'à acceptation de l'aller
                'user_id' => $data['user_id'] ?? null,
                'client_name' => $data['client_name'] ?? null,
            ]);
        }

        // Créer la course retour cachée si aller-retour
        if ($isRecurring && $isRound && isset($data['return_time'])) {
            Booking::create([
                'from_location' => $data['to_location'],   // inversé
                'to_location' => $data['from_location'],
                'from_lng' => $data['to_lng'],
                'from_lat' => $data['to_lat'],
                'to_lng' => $data['from_lng'],
                'to_lat' => $data['from_lat'],
                'distance' => $distance,
                'phone' => $data['phone'],
                'days' => $days,
                'remaining_days' => $days,
                'week_days' => $data['week_days'] ?? null,
                'round_trip' => true,
                'return_time' => null,
                'trip_type' => 'return',
                'pickup_date' => $data['pickup_date'],
                'pickup_time' => $data['return_time'],
                'special_requests' => $data['special_requests'] ?? null,
                'base_price' => $returnPrice,
                'total_price' => $totalPrice,
                'status' => 'pending',
                'is_recurring' => false, // ne recrée pas
                'parent_booking_id' => $booking->id,
                'subscription_driver_id' => null, // caché de tous jusqu'à acceptation
                'user_id' => $data['user_id'] ?? null,
                'client_name' => $data['client_name'] ?? null,
                'next_recurring_date' => null,
                'subscription_end_date' => $endDate ?? null,
            ]);
        }

        // ⚠️ Passe par le Notifier, qui porte la table de routage, plutôt que d'appeler
        // l'envoi directement : c'était le SEUL déclencheur de notification de toute
        // l'application, et son URL pointait vers une route Blade qu'un front Nuxt ne
        // sait pas ouvrir.
        app(Notifier::class)->bookingCreated($booking);

        return $booking;
    }
}
