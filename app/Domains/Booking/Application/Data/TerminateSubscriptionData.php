<?php

namespace App\Domains\Booking\Application\Data;

use App\Shared\Data\BaseData;

/** Le corps de POST /admin/bookings/{booking}/terminate-subscription — le motif, facultatif. */
final class TerminateSubscriptionData extends BaseData
{
    public function __construct(public ?string $cancellationReason = null) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return ['cancellation_reason' => ['nullable', 'string', 'max:1000']];
    }
}
