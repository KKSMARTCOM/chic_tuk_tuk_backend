<?php

namespace App\Domains\Booking\Application\Actions;

use App\Domains\Notification\Application\Notifier;
use App\Models\Booking;
use App\Models\Driver;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Annuler une course — ex-BookingService::cancel(), déplacée sans réécriture.
 *
 * ⚠️ Annuler CRÉE des courses. Trois cascades, qu'aucun lecteur ne devine depuis le nom
 * de la méthode :
 *
 *   CAS 1 — enfant d'abonnement : annulée, et une copie `pending` est créée, qui
 *           conserve subscription_driver_id — donc toujours le même agent.
 *   CAS 2 — abonnement parent : parent et enfants annulés, parent recréé SANS titulaire
 *           (visible de tous), course retour cachée recréée si aller-retour. La course
 *           retour du J1 n'est supprimée que s'il n'existe aucun enfant J2+.
 *   CAS 3 — course unique : annulée et recréée, avec sa course retour. `days` et
 *           `remaining_days` sont forcés à 1.
 *
 * Les prix propres à la course retour sont capturés AVANT toute suppression, dans les
 * cas 2 et 3. Déplacer ces deux lignes plus bas reviendrait à recréer la course retour
 * au prix de l'aller.
 */
final class CancelBooking
{
    public function __construct(private readonly Notifier $notifier) {}

    public function __invoke(string $bookingId, string $driverId, string $reason): Booking
    {
        $resultat = DB::transaction(function () use ($bookingId, $driverId, $reason) {

            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            if ($booking->driver_id !== $driverId) {
                throw new ApiException(404, 'NOT_FOUND', 'Accès non autorisé.');
            }

            if (!$booking->canBeCancelled()) {
                throw new ApiException(409, 'BOOKING_NOT_CANCELLABLE', 'Cette réservation ne peut plus être annulée.');
            }

            // CAS 1 — Course enfant d'abonnement → révocation
            if ($booking->is_subscription_child) {
                $booking->update([
                    'status'              => 'cancelled',
                    'cancelled_at'        => now(),
                    'cancellation_reason' => $reason,
                ]);

                Booking::create([
                    'from_location'          => $booking->from_location,
                    'to_location'            => $booking->to_location,
                    'from_lng'               => $booking->from_lng,
                    'from_lat'               => $booking->from_lat,
                    'to_lng'                 => $booking->to_lng,
                    'to_lat'                 => $booking->to_lat,
                    'distance'               => $booking->distance,
                    'phone'                  => $booking->phone,
                    'days'                   => $booking->days,
                    'remaining_days'         => $booking->remaining_days,
                    'week_days'              => $booking->week_days,
                    'round_trip'             => $booking->round_trip,
                    'return_time'            => $booking->return_time,
                    'trip_type'              => $booking->trip_type,
                    'pickup_date'            => $booking->pickup_date,
                    'pickup_time'            => $booking->pickup_time,
                    'special_requests'       => $booking->special_requests,
                    'tourist_circuit_id'     => $booking->tourist_circuit_id,
                    'discount'               => $booking->discount,
                    'promo_code_id'          => $booking->promo_code_id,
                    'base_price'             => $booking->base_price,
                    'total_price'            => $booking->total_price,
                    'status'                 => 'pending',
                    'is_recurring'           => $booking->is_recurring,
                    'parent_booking_id'      => $booking->parent_booking_id,
                    'subscription_driver_id' => $booking->subscription_driver_id, // toujours A
                    'user_id'                => $booking->user_id,
                    'client_name'            => $booking->client_name,
                    'next_recurring_date'    => null,
                ]);

                return $booking;
            }

            // CAS 2 — Abonnement parent → annulation + recréation sans agent
            if ($booking->is_subscription_parent && $booking->is_recurring) {
                // Capturer le prix propre à la course retour AVANT toute suppression/écrasement
                $oldReturn = Booking::where('parent_booking_id', $booking->id)
                    ->where('trip_type', 'return')
                    ->first();

                $returnBasePrice  = $oldReturn->base_price  ?? $booking->base_price;
                $returnTotalPrice = $oldReturn->total_price ?? $booking->total_price;

                $booking->update([
                    'status'              => 'cancelled',
                    'cancelled_at'        => now(),
                    'cancellation_reason' => $reason,
                ]);

                // Vérifier s'il y a déjà des enfants J2+ (courses aller uniquement)
                $hasChildren = Booking::where('parent_booking_id', $booking->id)
                    ->where('trip_type', 'go')
                    ->exists();

                if (!$hasChildren) {
                    // Pas encore d'enfants → supprimer la course retour cachée du J1
                    Booking::where('parent_booking_id', $booking->id)
                        ->where('trip_type', 'return')
                        ->delete();
                }

                // Recréer sans subscription_driver_id → visible par tous
                $newParent = Booking::create([
                    'from_location'         => $booking->from_location,
                    'to_location'           => $booking->to_location,
                    'from_lng'              => $booking->from_lng,
                    'from_lat'              => $booking->from_lat,
                    'to_lng'                => $booking->to_lng,
                    'to_lat'                => $booking->to_lat,
                    'distance'              => $booking->distance,
                    'phone'                 => $booking->phone,
                    'days'                  => $booking->days,
                    'remaining_days'        => $booking->remaining_days,
                    'week_days'             => $booking->week_days,
                    'round_trip'            => $booking->round_trip,
                    'return_time'           => $booking->return_time,
                    'trip_type'             => 'go',
                    'pickup_date'           => $booking->pickup_date,
                    'pickup_time'           => $booking->pickup_time,
                    'special_requests'      => $booking->special_requests,
                    'tourist_circuit_id'    => $booking->tourist_circuit_id,
                    'discount'              => $booking->discount,
                    'promo_code_id'         => $booking->promo_code_id,
                    'base_price'            => $booking->base_price,
                    'total_price'           => $booking->total_price,
                    'status'                => 'pending',
                    'is_recurring'          => true,
                    'next_recurring_date'   => $booking->next_recurring_date,
                    'subscription_end_date' => $booking->subscription_end_date,
                    'subscription_driver_id' => null,
                    'user_id'               => $booking->user_id,
                    'client_name'           => $booking->client_name,
                ]);

                // Recréer la course retour cachée si aller-retour
                if ($booking->round_trip && $booking->return_time) {
                    Booking::create([
                        'from_location'          => $booking->to_location,   // inversé
                        'to_location'            => $booking->from_location,
                        'from_lng'               => $booking->to_lng,
                        'from_lat'               => $booking->to_lat,
                        'to_lng'                 => $booking->from_lng,
                        'to_lat'                 => $booking->from_lat,
                        'distance'               => $booking->distance,
                        'phone'                  => $booking->phone,
                        'days'                   => $booking->days,
                        'remaining_days'         => $booking->remaining_days,
                        'week_days'              => $booking->week_days,
                        'round_trip'             => true,
                        'return_time'            => null,
                        'trip_type'              => 'return',
                        'pickup_date'            => $booking->pickup_date,
                        'pickup_time'            => $booking->return_time, // heure retour
                        'special_requests'       => $booking->special_requests,
                        'tourist_circuit_id'     => $booking->tourist_circuit_id,
                        'discount'               => $booking->discount,
                        'promo_code_id'          => $booking->promo_code_id,
                        'base_price'             => $returnBasePrice,
                        'total_price'            => $returnTotalPrice,
                        'status'                 => 'pending',
                        'is_recurring'           => false, // ne recrée pas
                        'parent_booking_id'      => $newParent->id,
                        'subscription_driver_id' => null, // cachée de tous
                        'user_id'                => $booking->user_id,
                        'client_name'            => $booking->client_name,
                        'subscription_end_date'  => $booking->subscription_end_date,
                    ]);
                }

                return $booking;
            }

            // CAS 3 — Course unique → annulation + recréation simple
            // Capturer le prix propre à la course retour AVANT toute suppression
            $oldReturn = Booking::where('parent_booking_id', $booking->id)
                ->where('trip_type', 'return')
                ->first();

            $returnBasePrice  = $oldReturn->base_price  ?? $booking->base_price;
            $returnTotalPrice = $oldReturn->total_price ?? $booking->total_price;

            $booking->update([
                'status'               => 'cancelled',
                'cancelled_at'         => now(),
                'cancellation_reason'  => $reason,
            ]);

            // Créer une nouvelle course avec les mêmes données
            $newBooking = Booking::create([
                'from_location'      => $booking->from_location,
                'to_location'        => $booking->to_location,
                'from_lng'           => $booking->from_lng,
                'from_lat'           => $booking->from_lat,
                'to_lng'             => $booking->to_lng,
                'to_lat'             => $booking->to_lat,
                'distance'           => $booking->distance,
                'phone'              => $booking->phone,
                'days'               => 1,
                'remaining_days'     => 1,
                'pickup_date'        => $booking->pickup_date,
                'pickup_time'        => $booking->pickup_time,
                'round_trip'         => $booking->round_trip,
                'return_time'        => $booking->return_time,
                'trip_type'          => $booking->trip_type,
                'special_requests'   => $booking->special_requests,
                'tourist_circuit_id' => $booking->tourist_circuit_id,
                'discount'           => $booking->discount,
                'promo_code_id'      => $booking->promo_code_id,
                'base_price'         => $booking->base_price,
                'total_price'        => $booking->total_price,
                'status'             => 'pending',
                'is_recurring'       => false,
                'user_id'            => $booking->user_id,
                'client_name'        => $booking->client_name,
            ]);

            // Si aller-retour → recréer aussi la course retour cachée liée au nouvel aller
            if ($booking->round_trip  && $booking->return_time) {
                // Supprimer l'ancienne course retour
                Booking::where('parent_booking_id', $booking->id)
                    ->where('trip_type', 'return')
                    ->delete();

                // Recréer liée au nouvel aller
                Booking::create([
                    'from_location'          => $booking->to_location,   // inversé
                    'to_location'            => $booking->from_location,
                    'from_lng'               => $booking->to_lng,
                    'from_lat'               => $booking->to_lat,
                    'to_lng'                 => $booking->from_lng,
                    'to_lat'                 => $booking->from_lat,
                    'distance'               => $booking->distance,
                    'phone'                  => $booking->phone,
                    'days'                   => 1,
                    'remaining_days'         => 1,
                    'pickup_date'            => $booking->pickup_date,
                    'pickup_time'            => $booking->return_time, // heure retour
                    'round_trip'             => true,
                    'return_time'            => null,
                    'trip_type'              => 'return',
                    'special_requests'       => $booking->special_requests,
                    'tourist_circuit_id'     => $booking->tourist_circuit_id,
                    'discount'               => $booking->discount,
                    'promo_code_id'          => $booking->promo_code_id,
                    'base_price'             => $returnBasePrice,
                    'total_price'            => $returnTotalPrice,
                    'status'                 => 'pending',
                    'is_recurring'           => false, // ne recrée pas
                    'parent_booking_id'      => $newBooking->id,
                    'subscription_driver_id' => null, // cachée de tous
                    'user_id'                => $booking->user_id,
                    'client_name'            => $booking->client_name,
                ]);
            }

            return $booking;
        });

        /**
         * ⚠️ APRÈS la transaction, jamais dedans : un push envoyé à l'intérieur
         * partirait pour une opération qu'un `rollback` annulerait ensuite. `Notifier`
         * n'échoue jamais bruyamment — une notification ne doit pas casser l'action.
         */
        $agent = Driver::with('user')->find($driverId)?->user;

        if ($agent) {
            $this->notifier->bookingCancelled($resultat, $agent, $reason);
        }

        return $resultat;
    }
}
