<?php

namespace App\Domains\Booking\Application\Actions;

use App\Domains\Notification\Application\Notifier;
use App\Models\Booking;
use App\Models\Driver;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Démarrer une course — ex-BookingService::start(), déplacée sans réécriture.
 *
 * ⚠️ Deux refus qui se ressemblent et n'ont pas la même cause : une course déjà
 * `in_progress`, et une course antérieure non soldée. Le second repose sur
 * Driver::hasBlockingPreviousBookings(), dont la comparaison a été corrigée le
 * 2026-09-18 — elle ignorait jusque-là les courses antérieures du même jour.
 */
final class StartBooking
{
    public function __construct(private readonly Notifier $notifier) {}

    public function __invoke(string $bookingId, string $driverId): Booking
    {
        $resultat = DB::transaction(function () use ($bookingId, $driverId) {

            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            $driver = Driver::lockForUpdate()->findOrFail($driverId);

            if ($booking->driver_id !== $driverId || $booking->status !== 'confirmed') {
                // ⚠️ Un seul code pour DEUX situations — « pas à cet agent » et « pas
                // confirmed » — parce que le code d'origine les teste dans une seule
                // condition. Les séparer donnerait un 404 sur la course d'autrui, donc
                // un changement de comportement. C'est une simplification qui attend un
                // test qui la couvre.
                throw new ApiException(409, 'BOOKING_NOT_STARTABLE', 'Démarrage non autorisé.');
            }

            $hasOngoingTrip = Booking::where('driver_id', $driverId)
                ->where('status', 'in_progress')
                ->lockForUpdate()
                ->exists();

            if ($hasOngoingTrip) {
                throw new ApiException(
                    409,
                    'BOOKING_ALREADY_IN_PROGRESS',
                    'Vous avez déjà une course en cours.'
                );
            }

            if ($driver->hasBlockingPreviousBookings($booking)) {
                throw new ApiException(
                    409,
                    'BOOKING_PREVIOUS_PENDING',
                    'Vous devez terminer ou annuler toutes les courses précédentes avant de démarrer celle-ci.'
                );
            }

            $booking->update([
                'status'      => 'in_progress',
                'started_at'  => now(),
            ]);

            return $booking;
        });

        /**
         * ⚠️ APRÈS la transaction, jamais dedans : un push envoyé à l'intérieur
         * partirait pour une opération qu'un `rollback` annulerait ensuite. `Notifier`
         * n'échoue jamais bruyamment — une notification ne doit pas casser l'action.
         */
        $agent = Driver::with('user')->find($driverId)?->user;

        if ($agent) {
            $this->notifier->bookingStarted($resultat, $agent);
        }

        return $resultat;
    }
}
