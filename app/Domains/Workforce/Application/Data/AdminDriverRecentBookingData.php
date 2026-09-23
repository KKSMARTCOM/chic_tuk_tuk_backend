<?php

namespace App\Domains\Workforce\Application\Data;

use App\Domains\Booking\Application\Data\Concerns\MapsBookingSchedule;
use App\Models\Booking;
use App\Shared\Data\BaseData;

/** Une ligne du cadre « Dernières courses » du dossier agent. */
final class AdminDriverRecentBookingData extends BaseData
{
    use MapsBookingSchedule;

    public function __construct(
        public string $id,
        public string $status,
        public string $fromLocation,
        public string $toLocation,
        public string $pickupAt,
        public float $driverEarning,
        public float $commission,
    ) {}

    public static function fromModel(Booking $booking): self
    {
        return new self(
            id: $booking->id,
            status: $booking->status,
            fromLocation: $booking->from_location,
            toLocation: $booking->to_location,
            pickupAt: self::pickupAt($booking),
            driverEarning: (float) $booking->driver_earning,
            commission: (float) $booking->commission,
        );
    }
}
