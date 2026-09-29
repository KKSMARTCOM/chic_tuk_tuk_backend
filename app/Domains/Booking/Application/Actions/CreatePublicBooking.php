<?php

namespace App\Domains\Booking\Application\Actions;

use App\Domains\Booking\Application\Data\CreatePublicBookingData;
use App\Domains\Booking\Domain\Terms;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;

/**
 * Dépôt d'une demande de réservation depuis le tunnel public.
 *
 * Délègue à `CreateBooking`, la seule implémentation de la création (courses simples,
 * aller-retour, abonnements), partagée avec l'administration.
 */
final class CreatePublicBooking
{
    public function __construct(private readonly CreateBooking $createBooking) {}

    /**
     * La preuve d'acceptation des CGU se pose sur la réservation que le client a faite —
     * l'aller, ou le premier jour d'un abonnement. Ses retours et ses jours suivants y
     * remontent par `parent_booking_id`. Dans la même transaction que la création : une
     * réservation publique sans cette preuve ne doit pas pouvoir exister.
     */
    public function execute(CreatePublicBookingData $data): Booking
    {
        return DB::transaction(function () use ($data) {
            $booking = ($this->createBooking)($data->toServicePayload());

            $booking->forceFill([
                'terms_accepted_at' => now(),
                'terms_version' => Terms::VERSION,
            ])->save();

            return $booking;
        });
    }
}
