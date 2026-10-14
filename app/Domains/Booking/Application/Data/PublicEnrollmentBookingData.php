<?php

namespace App\Domains\Booking\Application\Data;

use App\Domains\Booking\Domain\Enums\WeekDays;
use App\Models\Booking;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

final class PublicEnrollmentBookingData extends BaseData
{
    public function __construct(
        public string $bookingNumber,
        public string $fromLocation,
        public string $toLocation,
        public string $pickupDate,
        public string $pickupTime,
        public bool $roundTrip,
        public ?string $returnTime,
        #[TypeScriptType('?'.WeekDays::class)]
        public ?string $weekDays,
        public int $days,
        public ?string $specialRequests,
    ) {}

    public static function fromBooking(Booking $booking): self
    {
        return new self(
            bookingNumber: $booking->booking_number,
            fromLocation: $booking->from_location,
            toLocation: $booking->to_location,
            pickupDate: $booking->pickup_date->toDateString(),
            pickupTime: substr((string) $booking->pickup_time, 0, 5),
            roundTrip: (bool) $booking->round_trip,
            returnTime: $booking->return_time ? substr((string) $booking->return_time, 0, 5) : null,
            weekDays: $booking->week_days,
            days: (int) ($booking->days ?? 1),
            specialRequests: $booking->special_requests,
        );
    }
}
