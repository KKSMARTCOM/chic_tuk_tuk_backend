<?php

namespace App\Domains\Finance\Application\Data;

use App\Models\Booking;
use App\Shared\Data\BaseData;

/** La course d'une commission, et ce que l'agent y a gagné. */
final class AdminCommissionBookingData extends BaseData
{
    public function __construct(
        public string $id,
        public ?string $bookingNumber,
        public float $driverEarning,
    ) {}

    public static function fromModel(Booking $booking): self
    {
        return new self($booking->id, $booking->booking_number, (float) $booking->driver_earning);
    }
}
