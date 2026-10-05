<?php

namespace App\Domains\Booking\Application\Data;

use App\Models\Driver;
use App\Shared\Data\BaseData;

/** Un agent proposé au filtre « Agent » de la liste des réservations (2026-10-05). */
final class AdminBookingDriverOptionData extends BaseData
{
    public function __construct(
        public string $id,
        public ?string $name,
    ) {}

    public static function fromModel(Driver $driver): self
    {
        return new self(id: $driver->id, name: $driver->user?->name);
    }
}
