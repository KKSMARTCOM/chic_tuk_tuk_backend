<?php

namespace App\Domains\Booking\Application\Actions;

use App\Domains\Booking\Domain\EnrollmentAccess;
use App\Models\SubscriptionEnrollment;
use App\Shared\Http\ApiException;

/** Repousser le lien de 30 jours ; le jeton ne change pas (spec 2026-10-14, §5.2). */
final class RenewEnrollmentLink
{
    public function __invoke(SubscriptionEnrollment $enrollment): SubscriptionEnrollment
    {
        if ($enrollment->submitted_at !== null) {
            throw new ApiException(409, 'ENROLLMENT_ALREADY_SUBMITTED', 'Cette inscription a déjà été envoyée : son lien ne sert plus.');
        }

        if (EnrollmentAccess::bookingClosed($enrollment)) {
            throw new ApiException(409, 'ENROLLMENT_BOOKING_CLOSED', 'Cet abonnement est annulé, expiré ou résilié : son lien ne peut plus servir.');
        }

        $enrollment->update(['token_expires_at' => now()->addDays(SubscriptionEnrollment::LINK_DAYS)]);

        return $enrollment;
    }
}
