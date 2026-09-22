<?php

namespace App\Domains\Booking\Application\Actions;

use App\Domains\Booking\Domain\BookingLifecycle;
use App\Models\Booking;
use App\Services\BookingService;
use App\Shared\Http\ApiException;

/**
 * Supprimer une réservation — ex-Admin\BookingController::destroy().
 *
 * ⚠️ Le contrôleur Blade appelait `BookingService::delete()` sans AUCUNE condition, alors
 * que sa propre vue calculait `$canDelete = in_array($booking->status, ['cancelled',
 * 'expired'])` pour décider d'afficher le bouton. La règle n'existait donc que dans le
 * gabarit : un appel direct supprimait n'importe quelle course, y compris une course
 * terminée dont la ligne de commission serait restée orpheline.
 *
 * La suppression n'est là que pour faire le ménage de ce qui n'a pas eu lieu. Une course
 * vivante se termine ou s'annule.
 */
final class DeleteAdminBooking
{
    public function __construct(private readonly BookingService $bookingService) {}

    public function __invoke(Booking $booking): void
    {
        if (! BookingLifecycle::canDelete($booking)) {
            throw new ApiException(
                409,
                'BOOKING_NOT_DELETABLE',
                'Seule une réservation annulée ou expirée peut être supprimée. Annulez-la d\'abord.'
            );
        }

        // La suppression elle-même reste dans `BookingService` : elle n'a pas été
        // transposée en action, et la dupliquer ici créerait la divergence que la
        // délégation cherche à éviter.
        $this->bookingService->delete($booking->id);
    }
}
