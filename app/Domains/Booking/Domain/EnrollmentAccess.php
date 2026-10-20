<?php

namespace App\Domains\Booking\Domain;

use App\Models\SubscriptionEnrollment;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Le lien d'inscription est-il utilisable ? (spec 2026-10-14, §4.1)
 *
 * ⚠️ La forme du jeton est vérifiée AVANT toute requête : PostgreSQL n'y est pour rien ici
 * (le jeton est une chaîne), mais une requête par essai de jeton fabriqué n'a pas lieu d'être.
 */
final class EnrollmentAccess
{
    public const AGENCY_PHONE = '+229 01 44 42 90 17';

    public static function find(string $token, bool $lock = false): SubscriptionEnrollment
    {
        if (! SubscriptionEnrollment::isWellFormedToken($token)) {
            throw (new ModelNotFoundException)->setModel(SubscriptionEnrollment::class);
        }

        return SubscriptionEnrollment::query()
            ->with('booking')
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->where('token', $token)
            ->firstOrFail();
    }

    /**
     * L'abonnement ne prend plus d'inscription : annulé, expiré, ou résilié (2026-10-15 — le
     * premier jour d'un abonnement résilié garde son statut, seule la date le dit).
     */
    public static function bookingClosed(SubscriptionEnrollment $enrollment): bool
    {
        $booking = $enrollment->booking;

        return ! $booking
            || ! $booking->is_recurring
            || in_array($booking->status, ['cancelled', 'expired'], true)
            || $booking->subscription_terminated_at !== null;
    }

    public static function assertOpen(SubscriptionEnrollment $enrollment): void
    {
        if ($enrollment->submitted_at !== null) {
            throw new ApiException(410, 'ENROLLMENT_ALREADY_SUBMITTED', 'Cette inscription a déjà été envoyée. Merci !');
        }
        if ($enrollment->token_expires_at->isPast()) {
            throw new ApiException(410, 'ENROLLMENT_LINK_EXPIRED', 'Ce lien a expiré. Contactez-nous au '.self::AGENCY_PHONE.' pour en recevoir un nouveau.');
        }
        if (self::bookingClosed($enrollment)) {
            throw new ApiException(410, 'ENROLLMENT_BOOKING_CLOSED', 'Cet abonnement n\'est plus actif. Contactez-nous au '.self::AGENCY_PHONE.'.');
        }
    }
}
