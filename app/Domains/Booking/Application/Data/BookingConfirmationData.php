<?php

namespace App\Domains\Booking\Application\Data;

use App\Domains\Booking\Domain\Enums\BookingStatus;
use App\Models\Booking;
use App\Shared\Data\BaseData;
use Carbon\CarbonInterface;

/**
 * Confirmation renvoyée au client après dépôt d'une demande publique.
 *
 * Volontairement minimale : pas d'identifiant interne, pas de données d'agent ni de
 * commission. L'endpoint est anonyme, tout ce qui est renvoyé ici est public.
 */
class BookingConfirmationData extends BaseData
{
    public function __construct(
        public string        $bookingNumber,
        public BookingStatus $status,
        public string        $statusLabel,
        public string        $fromLocation,
        public string        $toLocation,
        public string        $pickupDate,
        public string        $pickupTime,
        public bool          $roundTrip,
        public ?string       $returnTime,
        public bool          $isRecurring,
        public int           $days,
        public int           $totalPrice,
        /** Le lien d'inscription d'un abonnement (2026-10-14) ; nul pour une course simple. */
        public ?string       $enrollmentUrl = null,
    ) {}

    public static function fromModel(Booking $booking): self
    {
        $status = BookingStatus::from($booking->status);

        return new self(
            bookingNumber: $booking->booking_number,
            status: $status,
            statusLabel: $status->label(),
            fromLocation: $booking->from_location,
            toLocation: $booking->to_location,
            // pickup_date est casté en date par le modèle : sans formatage explicite,
            // la sérialisation produit « 2026-09-18 00:00:00 ».
            pickupDate: $booking->pickup_date instanceof CarbonInterface
                ? $booking->pickup_date->toDateString()
                : (string) $booking->pickup_date,
            pickupTime: substr((string) $booking->pickup_time, 0, 5),
            roundTrip: (bool) $booking->round_trip,
            returnTime: $booking->return_time ? (string) $booking->return_time : null,
            isRecurring: (bool) $booking->is_recurring,
            days: (int) ($booking->days ?? 1),
            totalPrice: (int) $booking->total_price,
            enrollmentUrl: $booking->is_recurring ? $booking->enrollment?->url() : null,
        );
    }
}
