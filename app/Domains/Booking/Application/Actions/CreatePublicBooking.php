<?php

namespace App\Domains\Booking\Application\Actions;

use App\Domains\Booking\Application\Data\CreatePublicBookingData;
use App\Models\Booking;

/**
 * Dépôt d'une demande de réservation depuis le tunnel public.
 *
 * Délègue à `CreateBooking`, la seule implémentation de la création (courses simples,
 * aller-retour, abonnements), partagée avec l'administration.
 */
final class CreatePublicBooking
{
    public function __construct(private readonly CreateBooking $createBooking) {}

    public function execute(CreatePublicBookingData $data): Booking
    {
        return ($this->createBooking)($data->toServicePayload());
    }
}
