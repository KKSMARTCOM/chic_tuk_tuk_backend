<?php

namespace App\Domains\Booking\Application\Actions;

use App\Domains\Booking\Application\Data\UpdateAdminBookingData;
use App\Domains\Booking\Domain\BookingLifecycle;
use App\Models\Booking;
use App\Services\BookingService;
use App\Shared\Http\ApiException;

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
    public function __construct(private readonly BookingService $bookings) {}

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
        $this->bookings->update($booking, $data->toServicePayload());

        return $booking->refresh();
    }
}
