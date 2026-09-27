<?php

namespace Tests\Feature\Payment;

use App\Domains\Finance\Application\Actions\ComputeDriverSubscriptionRevenue;
use App\Domains\Finance\Application\Actions\CreatePayment;
use App\Models\Booking;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Le scénario qui a motivé ce lot : un abonnement de 15 courses dont l'agent titulaire
 * (A1) en effectue 8 et en révoque 7, récupérées par deux autres agents (A2 en fait 4,
 * A3 en fait 3). Chaque agent ne doit voir que ce qu'il a lui-même terminé.
 */
class DriverSubscriptionRevenueTest extends TestCase
{
    use RefreshDatabase;

    private function makeDriver(): Driver
    {
        $user = User::create([
            'name' => 'Agent '.Str::random(6),
            'email' => Str::uuid().'@example.test',
            'phone' => '90'.random_int(100000, 999999),
            'profil' => 'driver',
            'password' => bcrypt('secret'),
        ]);

        return Driver::create(['id' => (string) Str::uuid(), 'user_id' => $user->id]);
    }

    private function bookingDefaults(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'booking_number' => 'CTT-'.strtoupper(Str::random(8)),
            'status' => 'completed',
            'base_price' => 1333,
            'total_price' => 1333,
            'pickup_date' => now()->toDateString(),
            'pickup_time' => '08:00:00',
            'from_location' => 'Cotonou',
            'to_location' => 'Calavi',
            'distance' => 10,
        ];
    }

    public function test_repartit_le_revenu_dun_abonnement_entre_les_agents_qui_lont_execute(): void
    {
        $a1 = $this->makeDriver();
        $a2 = $this->makeDriver();
        $a3 = $this->makeDriver();

        $parent = Booking::create(array_merge($this->bookingDefaults(), [
            'driver_id' => $a1->id,
            'is_recurring' => true,
            'driver_earning' => 1000,
        ]));

        // A1 a effectué le parent + 7 enfants (8 courses au total).
        for ($i = 0; $i < 7; $i++) {
            Booking::create(array_merge($this->bookingDefaults(), [
                'driver_id' => $a1->id,
                'parent_booking_id' => $parent->id,
                'driver_earning' => 1000,
            ]));
        }

        // A1 en révoque 7 : 4 récupérées par A2, 3 par A3.
        for ($i = 0; $i < 4; $i++) {
            Booking::create(array_merge($this->bookingDefaults(), [
                'driver_id' => $a2->id,
                'parent_booking_id' => $parent->id,
                'is_revoked' => true,
                'driver_earning' => 1000,
            ]));
        }
        for ($i = 0; $i < 3; $i++) {
            Booking::create(array_merge($this->bookingDefaults(), [
                'driver_id' => $a3->id,
                'parent_booking_id' => $parent->id,
                'is_revoked' => true,
                'driver_earning' => 1000,
            ]));
        }

        $computeRevenue = app(ComputeDriverSubscriptionRevenue::class);

        $revenueA1 = $computeRevenue($a1->id);
        $this->assertSame(8000.0, $revenueA1['total_due']);
        $this->assertCount(1, $revenueA1['subscriptions']);
        $this->assertSame(8, $revenueA1['subscriptions'][0]['bookings_count']);
        $this->assertSame($parent->booking_number, $revenueA1['subscriptions'][0]['booking_number']);

        $revenueA2 = $computeRevenue($a2->id);
        $this->assertSame(4000.0, $revenueA2['total_due']);
        $this->assertSame(4, $revenueA2['subscriptions'][0]['bookings_count']);

        $revenueA3 = $computeRevenue($a3->id);
        $this->assertSame(3000.0, $revenueA3['total_due']);
        $this->assertSame(3, $revenueA3['subscriptions'][0]['bookings_count']);
    }

    public function test_ignore_les_courses_simples_et_les_simples_allers_retours(): void
    {
        $driver = $this->makeDriver();

        // Course unique, sans lien à un abonnement.
        Booking::create(array_merge($this->bookingDefaults(), [
            'driver_id' => $driver->id,
            'driver_earning' => 1000,
        ]));

        // Aller-retour simple : porte un parent_booking_id, mais le parent n'est PAS
        // récurrent — exactement le piège que `is_subscription_child` évite.
        $simpleParent = Booking::create(array_merge($this->bookingDefaults(), [
            'driver_id' => $driver->id,
            'is_recurring' => false,
            'driver_earning' => 1000,
        ]));
        Booking::create(array_merge($this->bookingDefaults(), [
            'driver_id' => $driver->id,
            'parent_booking_id' => $simpleParent->id,
            'trip_type' => 'return',
            'driver_earning' => 1000,
        ]));

        $revenue = app(ComputeDriverSubscriptionRevenue::class)($driver->id);

        $this->assertSame(0.0, $revenue['total_due']);
        $this->assertCount(0, $revenue['subscriptions']);
    }

    public function test_le_paiement_de_revenus_abonnement_ne_peut_pas_depasser_le_solde_du(): void
    {
        $driver = $this->makeDriver();
        $parent = Booking::create(array_merge($this->bookingDefaults(), [
            'driver_id' => $driver->id,
            'is_recurring' => true,
            'driver_earning' => 5000,
        ]));

        $createPayment = app(CreatePayment::class);

        // Un premier paiement partiel passe.
        $createPayment([
            'driver_id' => $driver->id,
            'payment_type' => 'subscription_revenue',
            'amount' => 3000,
            'payment_method' => 'cash',
            'payment_date' => now()->toDateString(),
        ]);

        // Le solde restant est de 2000 : au-delà, le paiement est refusé.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('dépasse le revenu abonnement restant dû');

        $createPayment([
            'driver_id' => $driver->id,
            'payment_type' => 'subscription_revenue',
            'amount' => 2001,
            'payment_method' => 'cash',
            'payment_date' => now()->toDateString(),
        ]);
    }
}
