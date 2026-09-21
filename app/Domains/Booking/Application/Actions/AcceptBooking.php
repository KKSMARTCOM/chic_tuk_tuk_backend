<?php

namespace App\Domains\Booking\Application\Actions;

use App\Domains\Notification\Application\Notifier;
use App\Models\Booking;
use App\Models\Driver;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Accepter une course — ex-BookingService::take().
 *
 * Déplacée sans réécriture le 2026-09-18 : même transaction, même lockForUpdate, même
 * ordre d'opérations, mêmes messages. BookingService::take() délègue désormais ici, de
 * sorte qu'il n'existe qu'une seule implémentation pour le chemin Blade et pour l'API.
 *
 * Ne renvoie rien, comme la méthode d'origine : son DB::transaction n'était pas
 * `return`é. Lui faire renvoyer la course serait une réécriture, si petite soit-elle.
 */
final class AcceptBooking
{
    public function __construct(private readonly Notifier $notifier) {}

    public function __invoke(string $bookingId, string $driverId): void
    {
        DB::transaction(function () use ($bookingId, $driverId) {

            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            if ($booking->status !== 'pending' || $booking->driver_id) {
                // L'acceptation concurrente est le cas le plus fréquent : deux agents
                // touchent « accepter » à la seconde près, lockForUpdate en désigne un,
                // et le perdant doit lire « cette course vient d'être prise ».
                throw new ApiException(409, 'BOOKING_ALREADY_TAKEN', 'Réservation déjà prise ou annulée.');
            }

            if (!$booking->isVisibleToDriver($driverId)) {
                // 404 et non 403 : un refus qui révélerait l'existence d'une course la
                // rend introuvable. Le message reste celui du Blade — le front ne
                // l'affiche pas, il s'appuie sur le `code`.
                throw new ApiException(404, 'NOT_FOUND', 'Cette course n\'est pas accessible.');
            }

            $driver = Driver::lockForUpdate()->findOrFail($driverId);

            $updateData = [
                'driver_id' => $driver->id,
                'status'    => 'confirmed',
            ];

            // Abonnement parent → lier le titulaire + course retour abonnement
            if (
                $booking->is_recurring
                && is_null($booking->parent_booking_id)
                && !$booking->subscription_driver_id
            ) {
                $updateData['subscription_driver_id'] = $driver->id;

                Booking::where('parent_booking_id', $booking->id)
                    ->where('trip_type', 'return')
                    ->whereNull('subscription_driver_id')
                    ->update(['subscription_driver_id' => $driver->id]);
            }

            // Course unique aller avec aller-retour → lier l'agent à la course retour
            if (
                !$booking->is_recurring
                && $booking->round_trip
                && $booking->trip_type === 'go'
                && is_null($booking->parent_booking_id) // course aller principale
            ) {
                $updated = Booking::where('parent_booking_id', $booking->id)
                    ->where('trip_type', 'return')
                    ->whereNull('subscription_driver_id')
                    ->update(['subscription_driver_id' => $driver->id]);

                Log::info("[take] Courses retour simples liées à l'agent : {$updated}");
            }

            $booking->update($updateData);

            Log::info("[take] Booking {$bookingId} accepté par driver {$driverId}");
        });

        /**
         * ⚠️ La notification part APRÈS la transaction, jamais dedans.
         *
         * Dedans, un push serait envoyé pour une opération qu'un `rollback` ultérieur
         * annulerait — et l'agent lirait une alerte pour une course qui n'a jamais été
         * acceptée. `Notifier` n'échoue par ailleurs jamais bruyamment : une notification
         * est un effet de bord, elle ne doit pas pouvoir casser l'action métier.
         */
        $this->previenir($bookingId, $driverId);
    }

    private function previenir(string $bookingId, string $driverId): void
    {
        $booking = Booking::find($bookingId);
        $agent = Driver::with('user')->find($driverId)?->user;

        if ($booking && $agent) {
            $this->notifier->bookingAccepted($booking, $agent);
        }
    }
}
