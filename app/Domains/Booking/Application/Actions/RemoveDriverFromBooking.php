<?php

namespace App\Domains\Booking\Application\Actions;

use App\Models\Booking;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Retirer l'agent d'une course — ex-Admin\BookingController::removeDriver().
 *
 * La course redevient `pending` et retourne donc au vivier des courses disponibles.
 *
 * ⚠️ Sur un ABONNEMENT, retirer l'agent retire aussi le TITULAIRE
 * (`subscription_driver_id`), sans quoi l'abonnement resterait réservé à quelqu'un qui
 * n'y est plus affecté et invisible de tous les autres. Et c'est refusé dès qu'une course
 * fille existe : ces courses-là portent déjà le titulaire, et le retirer du parent seul
 * laisserait les deux en désaccord.
 *
 * ⚠️ Cette action ne traverse PLUS `BookingService::update()`. Sa branche
 * `is_subscription_parent` y annulait les courses filles `pending` et `confirmed` à
 * chaque mise à jour partielle : retirer l'agent d'un abonnement annulait donc tout son
 * calendrier à venir. Voir `ChangeBookingStatus`, où la propagation revient — à
 * l'annulation seule, qui est le geste qui la justifie.
 */
final class RemoveDriverFromBooking
{
    /** Les statuts où la course est encore devant nous et peut changer de mains. */
    private const REMOVABLE_STATUSES = ['confirmed', 'in_progress'];

    public function __invoke(Booking $booking): Booking
    {
        if (! $booking->driver_id) {
            throw new ApiException(409, 'BOOKING_NO_DRIVER', 'Aucun agent n\'est affecté à cette course.');
        }

        if (! in_array($booking->status, self::REMOVABLE_STATUSES, true)) {
            throw new ApiException(
                409,
                'BOOKING_DRIVER_NOT_REMOVABLE',
                'L\'agent ne peut plus être retiré pour ce statut de réservation.'
            );
        }

        return DB::transaction(function () use ($booking) {
            if ($booking->is_subscription_parent) {
                $aDesCoursesFilles = Booking::where('parent_booking_id', $booking->id)->exists();

                if ($aDesCoursesFilles) {
                    throw new ApiException(
                        409,
                        'SUBSCRIPTION_HAS_CHILDREN',
                        'Des courses filles existent déjà : vous ne pouvez pas retirer l\'agent de l\'abonnement.'
                    );
                }

                $booking->subscription_driver_id = null;
            }

            $booking->driver_id = null;
            $booking->status = 'pending';
            $booking->save();

            return $booking->refresh();
        });
    }
}
