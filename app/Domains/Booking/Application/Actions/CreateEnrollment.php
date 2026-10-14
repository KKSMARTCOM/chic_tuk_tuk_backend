<?php

namespace App\Domains\Booking\Application\Actions;

use App\Models\Booking;
use App\Models\SubscriptionEnrollment;

/**
 * L'inscription vide d'un abonnement, avec son jeton (spec 2026-10-14, §4, étape 1).
 * Idempotente : un parent qui en a déjà une la garde.
 */
final class CreateEnrollment
{
    public function __invoke(Booking $parent): SubscriptionEnrollment
    {
        return $parent->enrollment()->firstOrCreate([], [
            'token' => SubscriptionEnrollment::newToken(),
            'token_expires_at' => now()->addDays(SubscriptionEnrollment::LINK_DAYS),
        ]);
    }
}
