<?php

namespace App\Domains\Booking\Application\Actions;

use App\Domains\Booking\Domain\BookingLifecycle;
use App\Models\Booking;
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
    public function __invoke(Booking $booking): void
    {
        if (! BookingLifecycle::canDelete($booking)) {
            throw new ApiException(
                409,
                'BOOKING_NOT_DELETABLE',
                'Seule une réservation annulée ou expirée peut être supprimée. Annulez-la d\'abord.'
            );
        }

        // La suppression elle-même : ex-`BookingService::delete()`, déplacée ici sans
        // changement le 2026-09-27.
        $this->deleteBooking($booking->id);
    }

    private function deleteBooking(string $bookingId)
    {
        $booking = Booking::findOrFail($bookingId);

        if (! $booking) {
            throw new \Exception('La course demandée est introuvable.');
        }

        if (! in_array($booking->status, ['cancelled', 'expired'])) {
            throw new \Exception('Cette course ne peut pas être supprimée car elle est en cours ou en attente.');
        }

        $booking->delete();
    }
}
