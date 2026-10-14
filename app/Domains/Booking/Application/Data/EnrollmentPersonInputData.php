<?php

namespace App\Domains\Booking\Application\Data;

use App\Shared\Data\BaseData;

final class EnrollmentPersonInputData extends BaseData
{
    public function __construct(
        public ?string $fullName = null,
        public ?int $age = null,
        public ?string $schoolClass = null,
        public ?string $healthNotes = null,
        public ?string $profession = null,
    ) {}
}
