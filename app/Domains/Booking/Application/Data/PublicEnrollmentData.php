<?php

namespace App\Domains\Booking\Application\Data;

use App\Models\SubscriptionEnrollment;
use App\Shared\Data\BaseData;

/** GET /public/enrollments/{token} — le rappel de la réservation, rien d'autre (spec §5.1). */
final class PublicEnrollmentData extends BaseData
{
    public function __construct(
        public PublicEnrollmentBookingData $booking,
        /** Prérempli dans le champ WhatsApp. */
        public ?string $phone,
    ) {}

    public static function fromEnrollment(SubscriptionEnrollment $enrollment): self
    {
        return new self(PublicEnrollmentBookingData::fromBooking($enrollment->booking), $enrollment->booking->phone);
    }
}
