<?php

namespace App\Domains\Booking\Application\Actions;

use App\Domains\Booking\Application\Data\UpdateAdminBookingData;
use App\Domains\Booking\Domain\BookingLifecycle;
use App\Domains\Booking\Domain\PriceCalculator;
use App\Models\Booking;
use App\Shared\Http\ApiException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Modifier le trajet et le prix d'une réservation EN ATTENTE — ex-Admin\BookingController::update().
 *
 * ⚠️ Garde `BookingLifecycle::canEdit()` en amont, ce que le contrôleur Blade ne faisait
 * pas : `update()` acceptait n'importe quel statut de départ, la vue se contentant de ne
 * pas AFFICHER le lien « Modifier » ailleurs qu'en attente. Un appel direct contournait
 * donc la règle — le même défaut que celui fermé sur l'affectation et le retrait d'agent.
 */
final class UpdateAdminBooking
{
    public function __construct(
        private readonly PriceCalculator $pricingService,
        private readonly MeasureRouteDistance $measureDistance,
    ) {}

    public function __invoke(Booking $booking, UpdateAdminBookingData $data): Booking
    {
        if (! BookingLifecycle::canEdit($booking)) {
            throw new ApiException(
                409,
                'BOOKING_NOT_EDITABLE',
                'Seule une réservation en attente peut être modifiée.'
            );
        }

        // Chemin COMPLET (sans `_partial`) : c'est lui qui recalcule distance et prix
        // quand le trajet change, et conserve le statut courant puisque `status` n'est
        // jamais dans la charge utile.
        $this->applyUpdate($booking, $data->toServicePayload());

        return $booking->refresh();
    }

    private function applyUpdate(Booking $booking, array $data)
    {
        return DB::transaction(function () use ($booking, $data) {

            // La branche `_partial` (statut, retrait d'agent) a disparu avec le Blade le
            // 2026-09-27 : l'API passe par ChangeBookingStatus et RemoveDriverFromBooking.

            // Recalcul distance & prix
            $distance = $booking->distance;
            $basePrice = $booking->base_price;

            $fromChanged = isset($data['from_lng']) && (float) $data['from_lng'] !== (float) $booking->from_lng;
            $toChanged = isset($data['to_lng']) && (float) $data['to_lng'] !== (float) $booking->to_lng;

            if ($fromChanged || $toChanged) {
                $distance = ($this->measureDistance)($data['from_lng'], $data['from_lat'], $data['to_lng'], $data['to_lat']);

                if ($distance === null) {
                    throw new \Exception('Erreur lors du calcul de l\'itinéraire.');
                }

                $basePrice = $this->pricingService->getPrice($distance);
            } elseif (isset($data['base_price'])) {
                $basePrice = (float) $data['base_price'];
            }

            // Recalcul total si prix modifié manuellement ou trajet changé
            $isRound = (bool) ($data['round_trip'] ?? $booking->round_trip ?? false);
            $days = (int) ($data['days'] ?? $booking->days ?? 1);
            $weekDays = $data['week_days'] ?? $booking->week_days ?? 'lun_dim';

            // Heures effectives (nouvelles si fournies, sinon celles déjà en base)
            $pickupTime = $data['pickup_time'] ?? $booking->pickup_time;
            $returnTime = $isRound ? ($data['return_time'] ?? $booking->return_time) : null;

            // Prix par trajet, avec majoration selon l'heure propre à chacun
            $goPrice = $this->pricingService->applyTimeSurcharge($basePrice, $pickupTime);
            $returnPrice = $returnTime
                ? $this->pricingService->applyTimeSurcharge($basePrice, $returnTime)
                : $goPrice;

            $tripPrice = $isRound ? $goPrice + $returnPrice : $goPrice;
            $totalPrice = $days > 1 ? $tripPrice * $days : $tripPrice;

            // Recalcul récurrence
            $isRecurring = $booking->is_recurring;
            $nextRecurringDate = $booking->next_recurring_date;
            $subscriptionEndDate = $booking->subscription_end_date;

            $daysChanged = isset($data['days']) && (int) $data['days'] !== (int) $booking->days;
            $dateChanged = isset($data['pickup_date']) && $data['pickup_date'] !== $booking->pickup_date->toDateString();
            $weekChanged = isset($data['week_days']) && $data['week_days'] !== $booking->week_days;

            if ($daysChanged || $dateChanged || $weekChanged) {
                $isRecurring = ($days > 1);
                $pickupDate = $data['pickup_date'] instanceof Carbon ? $data['pickup_date']->toDateString() : ($data['pickup_date'] ?? $booking->pickup_date->toDateString());
                if ($isRecurring) {
                    // Prochain jour autorisé après J1 → heure du cron
                    $firstNext = getNextAllowedDay(Carbon::parse($pickupDate), $weekDays);
                    $nextRecurringDate = $firstNext?->copy()->subDay()->setTime(1, 0);

                    // Date de fin selon jours ouvrés
                    $subscriptionEndDate = calculateEndDate($pickupDate, $days, $weekDays);
                } else {
                    $nextRecurringDate = null;
                    $subscriptionEndDate = null;
                }
            }

            // Capturer AVANT la mise à jour
            $wasRoundTrip = (bool) $booking->round_trip;

            // Mise à jour
            $booking->update(
                [
                    'user_id' => $data['user_id'] ?? $booking->user_id,
                    'client_name' => isset($data['client_name']) && ! empty($data['client_name']) ? $data['client_name'] : ($booking->client_name ?? 'Client'),
                    'from_location' => $data['from_location'] ?? $booking->from_location,
                    'to_location' => $data['to_location'] ?? $booking->to_location,
                    'from_lng' => $data['from_lng'] ?? $booking->from_lng,
                    'from_lat' => $data['from_lat'] ?? $booking->from_lat,
                    'to_lng' => $data['to_lng'] ?? $booking->to_lng,
                    'to_lat' => $data['to_lat'] ?? $booking->to_lat,
                    'phone' => $data['phone'] ?? $booking->phone,
                    'days' => $days,
                    'remaining_days' => $days,
                    'week_days' => $data['week_days'] ?? $booking->week_days,
                    'round_trip' => $isRound,
                    'return_time' => $isRound ? ($data['return_time'] ?? $booking->return_time) : null,
                    'pickup_date' => $data['pickup_date'] ?? $booking->pickup_date,
                    'pickup_time' => $data['pickup_time'] ?? $booking->pickup_time,
                    'status' => $data['status'] ?? $booking->status,
                    'special_requests' => $data['special_requests'] ?? $booking->special_requests,
                    'distance' => $distance,
                    'base_price' => $goPrice,
                    'total_price' => $totalPrice,
                    'is_recurring' => $isRecurring,
                    'next_recurring_date' => $nextRecurringDate,
                    'subscription_end_date' => $subscriptionEndDate,
                ]
            );

            // Cas désactivation aller-retour : était true, devient false
            if ($wasRoundTrip && ! $isRound) {
                Booking::where('parent_booking_id', $booking->id)
                    ->where('trip_type', 'return')
                    ->delete();
            }

            // Cas mise à jour ou activation aller-retour
            if ($isRound) {
                $hasChildrenGo = Booking::where('parent_booking_id', $booking->id)
                    ->where('trip_type', 'go')
                    ->exists();

                if (! $hasChildrenGo) {
                    $returnBooking = Booking::where('parent_booking_id', $booking->id)
                        ->where('trip_type', 'return')
                        ->first();

                    if ($returnBooking) {
                        // Mise à jour de la course retour existante
                        $returnBooking->update([
                            'from_location' => $data['to_location'] ?? $booking->to_location,
                            'to_location' => $data['from_location'] ?? $booking->from_location,
                            'from_lng' => $data['to_lng'] ?? $booking->to_lng,
                            'from_lat' => $data['to_lat'] ?? $booking->to_lat,
                            'to_lng' => $data['from_lng'] ?? $booking->from_lng,
                            'to_lat' => $data['from_lat'] ?? $booking->from_lat,
                            'pickup_date' => $data['pickup_date'] ?? $booking->pickup_date,
                            'pickup_time' => $data['return_time'] ?? $returnBooking->pickup_time,
                            'phone' => $data['phone'] ?? $booking->phone,
                            'days' => $days,
                            'remaining_days' => $days,
                            'week_days' => $data['week_days'] ?? $booking->week_days,
                            'distance' => $distance,
                            'base_price' => $returnPrice,
                            'total_price' => $totalPrice,
                            'special_requests' => $data['special_requests'] ?? $booking->special_requests,
                            'client_name' => $data['client_name'] ?? $booking->client_name,
                            'subscription_end_date' => $subscriptionEndDate,
                            'round_trip' => true,
                        ]);
                    } elseif (! $wasRoundTrip && isset($data['return_time'])) {
                        // Aller-retour nouvellement activé → créer la course retour
                        Booking::create([
                            'from_location' => $data['to_location'] ?? $booking->to_location,
                            'to_location' => $data['from_location'] ?? $booking->from_location,
                            'from_lng' => $data['to_lng'] ?? $booking->to_lng,
                            'from_lat' => $data['to_lat'] ?? $booking->to_lat,
                            'to_lng' => $data['from_lng'] ?? $booking->from_lng,
                            'to_lat' => $data['from_lat'] ?? $booking->from_lat,
                            'distance' => $distance,
                            'phone' => $data['phone'] ?? $booking->phone,
                            'days' => $days,
                            'remaining_days' => $days,
                            'week_days' => $weekDays,
                            'round_trip' => true,
                            'return_time' => null,
                            'trip_type' => 'return',
                            'pickup_date' => $data['pickup_date'] ?? $booking->pickup_date,
                            'pickup_time' => $data['return_time'],
                            'special_requests' => $data['special_requests'] ?? $booking->special_requests,
                            'base_price' => $returnPrice,
                            'total_price' => $totalPrice,
                            'status' => 'pending',
                            'is_recurring' => false,
                            'parent_booking_id' => $booking->id,
                            'subscription_driver_id' => null,
                            'client_name' => $data['client_name'] ?? $booking->client_name,
                            'subscription_end_date' => $subscriptionEndDate,
                        ]);
                    }
                }
            }

            return $booking->refresh();
        });
    }
}
