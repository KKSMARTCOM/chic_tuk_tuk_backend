<?php

namespace Tests\Feature\Booking\Concerns;

use App\Models\Booking;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Fabrique agents, abonnements et courses enfants pour les tests de réservation.
 *
 * La branche de production n'a pas de fabriques de modèles hors `UserFactory` : ces
 * aides en tiennent lieu.
 */
trait BuildsSubscriptions
{
    protected function makeDriver(): Driver
    {
        $user = User::create([
            'name' => 'Agent '.Str::random(6),
            'email' => Str::uuid().'@example.test',
            'phone' => '90'.random_int(100000, 999999),
            'profil' => 'driver',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);

        return Driver::create(['id' => (string) Str::uuid(), 'user_id' => $user->id]);
    }

    /** Un abonnement de 3 jours, lun→dim, qui démarre le lundi 28 septembre 2026 à 8h. */
    protected function makeParent(array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'id' => (string) Str::uuid(),
            'booking_number' => 'CTT-'.strtoupper(Str::random(8)),
            'status' => 'confirmed',
            'base_price' => 1000,
            'total_price' => 3000,
            'pickup_date' => '2026-09-28',
            'pickup_time' => '08:00:00',
            'from_location' => 'Cotonou',
            'to_location' => 'Calavi',
            'from_lat' => 6.36, 'from_lng' => 2.42, 'to_lat' => 6.45, 'to_lng' => 2.35,
            'distance' => 10,
            'phone' => '97000000',
            'days' => 3,
            'remaining_days' => 3,
            'week_days' => 'lun_dim',
            'round_trip' => false,
            'trip_type' => 'go',
            'is_recurring' => true,
            'next_recurring_date' => '2026-09-28 01:00:00',
        ], $overrides));
    }

    protected function makeChild(Booking $parent, array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'id' => (string) Str::uuid(),
            'booking_number' => 'CTT-'.strtoupper(Str::random(8)),
            'status' => 'pending',
            'base_price' => $parent->base_price,
            'total_price' => $parent->total_price,
            'pickup_date' => '2026-09-29',
            'pickup_time' => '08:00:00',
            'from_location' => $parent->from_location,
            'to_location' => $parent->to_location,
            'distance' => $parent->distance,
            'phone' => $parent->phone,
            'days' => $parent->days,
            'remaining_days' => 0,
            'week_days' => $parent->week_days,
            'round_trip' => $parent->round_trip,
            'trip_type' => 'go',
            'is_recurring' => false,
            'parent_booking_id' => $parent->id,
            'subscription_driver_id' => $parent->subscription_driver_id,
        ], $overrides));
    }
}
