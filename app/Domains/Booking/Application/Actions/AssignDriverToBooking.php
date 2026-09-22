<?php

namespace App\Domains\Booking\Application\Actions;

use App\Models\Booking;
use App\Models\Driver;
use App\Services\BookingService;
use App\Shared\Http\ApiException;

/**
 * Affecter un agent à une course — ex-Admin\BookingController::assignDriver().
 *
 * ⚠️ L'affectation passe par `BookingService::take()`, qui délègue à `AcceptBooking`,
 * exactement comme si l'agent avait pris la course lui-même. C'est indispensable et non
 * une commodité : accepter une course propage `subscription_driver_id` sur la course
 * RETOUR cachée et sur les enfants d'un abonnement. Poser `driver_id` à la main —
 * tentation naturelle pour « simplifier » — laisserait le retour invisible de tous, et
 * le client sans agent pour rentrer.
 */
final class AssignDriverToBooking
{
    public function __construct(private readonly BookingService $bookingService) {}

    public function __invoke(Booking $booking, string $driverId): Booking
    {
        $driver = Driver::with('user')->find($driverId);

        if (! $driver) {
            throw new ApiException(404, 'DRIVER_NOT_FOUND', 'Cet agent n\'existe pas.');
        }

        // Repris du Blade : un compte désactivé, ou qui n'est plus un compte agent, ne
        // peut pas recevoir de course.
        if ($driver->user?->profil !== 'driver' || ! $driver->user?->is_active) {
            throw new ApiException(409, 'DRIVER_NOT_ASSIGNABLE', 'Cet agent n\'est pas disponible.');
        }

        if ($booking->driver_id) {
            throw new ApiException(
                409,
                'BOOKING_ALREADY_ASSIGNED',
                'Cette course a déjà un agent. Retirez-le avant d\'en affecter un autre.'
            );
        }

        $this->bookingService->take($booking->id, $driver->id);

        return $booking->refresh();
    }
}
