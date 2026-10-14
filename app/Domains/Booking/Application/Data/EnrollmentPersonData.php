<?php

namespace App\Domains\Booking\Application\Data;

use App\Models\SubscriptionEnrollmentPerson;
use App\Shared\Data\BaseData;

final class EnrollmentPersonData extends BaseData
{
    public function __construct(
        public int $position,
        public string $fullName,
        public ?int $age,
        public ?string $schoolClass,
        public ?string $healthNotes,
        public ?string $profession,
    ) {}

    public static function fromModel(SubscriptionEnrollmentPerson $person): self
    {
        return new self($person->position, $person->full_name, $person->age, $person->school_class, $person->health_notes, $person->profession);
    }
}
