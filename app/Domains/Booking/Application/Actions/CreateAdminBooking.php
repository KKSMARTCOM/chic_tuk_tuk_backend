<?php

namespace App\Domains\Booking\Application\Actions;

use App\Domains\Booking\Application\Data\CreateAdminBookingData;
use App\Models\Booking;
use App\Services\BookingService;

/**
 * Créer une réservation depuis l'administration — ex-Admin\BookingController::store().
 *
 * Délègue à `BookingService::create()`, comme `CreatePublicBooking` : c'est la seule
 * implémentation de la création (courses simples, aller-retour, abonnements), et il n'y a
 * pas lieu d'en tenir une seconde ici.
 */
final class CreateAdminBooking
{
    public function __construct(private readonly BookingService $bookings) {}

    public function __invoke(CreateAdminBookingData $data): Booking
    {
        return $this->bookings->create($data->toServicePayload());
    }
}
