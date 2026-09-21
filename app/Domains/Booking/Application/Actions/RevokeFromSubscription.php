<?php

namespace App\Domains\Booking\Application\Actions;

use App\Domains\Notification\Application\Notifier;
use App\Models\Booking;
use App\Models\Driver;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Révoquer une course d'abonnement — ex-BookingService::revokeFromSubscription().
 *
 * Déplacée sans réécriture. La course redevient `pending`, perd ses deux agents et
 * porte `is_revoked` : elle est alors visible de tous.
 */
final class RevokeFromSubscription
{
    public function __construct(private readonly Notifier $notifier) {}

    public function __invoke(string $bookingId, string $driverId): Booking
    {
        $resultat = DB::transaction(function () use ($bookingId, $driverId) {

            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            // Seul l'agent lié peut révoquer
            if ($booking->subscription_driver_id !== $driverId) {
                throw new ApiException(404, 'NOT_FOUND', 'Vous n\'êtes pas autorisé à révoquer cette course.');
            }

            if (!in_array($booking->status, ['pending', 'confirmed'])) {
                throw new ApiException(409, 'BOOKING_NOT_REVOCABLE', 'Cette course ne peut plus être révoquée.');
            }

            $booking->update([
                'driver_id'   => null,
                'status'      => 'pending',
                'is_revoked'  => true,
                'revoked_at'  => now(),
                'revoked_by'  => $driverId,
                'subscription_driver_id' => null,
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
            $this->notifier->subscriptionRevoked($resultat, $agent);
        }

        return $resultat;
    }
}
