<?php

namespace Tests\Feature\Booking;

use App\Domains\Booking\Application\Actions\ListAssignedBookings;
use App\Models\Booking;
use App\Models\Driver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignedBookingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_equivalence_avec_l_appel_du_blade(): void
    {
        // Le Blade appelle getByDriverId($id, ['confirmed', 'in_progress']).
        // Ce que rendait cet appel, relevé le 2026-09-27 avant la suppression du Blade :
        // la course en cours (7 h) puis l'acceptée (9 h) ; ni la terminée ni la libre.
        $driver = Driver::factory()->create();
        $confirmed = Booking::factory()->confirmed($driver)->create(['pickup_time' => '09:00']);
        $inProgress = Booking::factory()->inProgress($driver)->create(['pickup_time' => '07:00']);
        Booking::factory()->completed($driver)->create(['pickup_time' => '08:00']);
        Booking::factory()->create(); // course libre, d'aucun agent

        $nouvelle = app(ListAssignedBookings::class)($driver->id)->pluck('id')->all();

        $this->assertSame([$inProgress->id, $confirmed->id], $nouvelle);
    }

    public function test_ne_renvoie_ni_les_courses_terminees_ni_celles_d_un_autre_agent(): void
    {
        $driver = Driver::factory()->create();
        $autre = Driver::factory()->create();
        $mienne = Booking::factory()->confirmed($driver)->create();
        $terminee = Booking::factory()->completed($driver)->create();
        $sienne = Booking::factory()->confirmed($autre)->create();

        $vu = app(ListAssignedBookings::class)($driver->id)->pluck('id')->all();

        $this->assertContains($mienne->id, $vu);
        $this->assertNotContains($terminee->id, $vu);
        $this->assertNotContains($sienne->id, $vu);
    }

    public function test_l_ordre_est_ascendant_par_heure_de_prise_en_charge(): void
    {
        $driver = Driver::factory()->create();
        $tard = Booking::factory()->confirmed($driver)->create(['pickup_time' => '17:00']);
        $tot = Booking::factory()->confirmed($driver)->create(['pickup_time' => '06:00']);

        $vu = app(ListAssignedBookings::class)($driver->id)->pluck('id')->all();

        $this->assertSame([$tot->id, $tard->id], $vu);
    }

    public function test_le_parent_est_charge_pour_eviter_une_requete_par_ligne(): void
    {
        // is_subscription_child et subscription_label lisent parentBooking. Sans eager
        // load, une liste de dix courses fait onze requêtes, et ça ne se voit qu'en
        // production.
        $driver = Driver::factory()->create();
        $parent = Booking::factory()->subscriptionParent()->create();
        Booking::factory()->subscriptionChild($parent)->create([
            'driver_id' => $driver->id,
            'status' => 'confirmed',
        ]);

        $booking = app(ListAssignedBookings::class)($driver->id)->first();

        $this->assertTrue($booking->relationLoaded('parentBooking'));
    }
}
