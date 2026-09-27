<?php

namespace Tests\Feature\Booking\Characterization;

use App\Domains\Booking\Application\Actions\CompleteBooking;
use App\Models\Booking;
use App\Models\Commission;
use App\Models\Driver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompleteBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_commission_vaut_15_pourcent_arrondis_aux_50_fcfa_superieurs(): void
    {
        // La formule exacte du code :
        //   (int) ceil((($base * 15) / 100) / 50) * 50
        // Sur 5000 : 750 pile. Le cas intéressant est celui qui ne tombe pas juste.
        $driver = Driver::factory()->create();
        $booking = Booking::factory()->inProgress($driver)->create(['base_price' => 5300]);

        app(CompleteBooking::class)($booking->id, $driver->id);

        $booking->refresh();
        // 5300 × 15 % = 795 → arrondi aux 50 supérieurs = 800.
        $this->assertSame(800.0, (float) $booking->commission);
        $this->assertSame(4500.0, (float) $booking->driver_earning);
        $this->assertSame('completed', $booking->status);
        $this->assertNotNull($booking->completed_at);
    }

    public function test_une_commission_qui_tombe_juste_n_est_pas_arrondie_au_cran_suivant(): void
    {
        // ceil() sur une valeur entière ne doit PAS ajouter 50. Ce test discrimine une
        // erreur de +50 systématique, que le cas précédent laisserait passer.
        $driver = Driver::factory()->create();
        $booking = Booking::factory()->inProgress($driver)->create(['base_price' => 5000]);

        app(CompleteBooking::class)($booking->id, $driver->id);

        $this->assertSame(750.0, (float) $booking->fresh()->commission);
    }

    public function test_le_compteur_de_courses_de_l_agent_est_incremente(): void
    {
        $driver = Driver::factory()->create(['total_trips' => 7]);
        $booking = Booking::factory()->inProgress($driver)->create();

        app(CompleteBooking::class)($booking->id, $driver->id);

        $this->assertSame(8, $driver->fresh()->total_trips);
    }

    public function test_une_ligne_de_commission_est_creee(): void
    {
        $driver = Driver::factory()->create();
        $booking = Booking::factory()->inProgress($driver)->create(['base_price' => 5000]);

        app(CompleteBooking::class)($booking->id, $driver->id);

        $commission = Commission::where('booking_id', $booking->id)->first();
        $this->assertNotNull($commission);
        $this->assertSame($driver->id, $commission->driver_id);
        $this->assertSame(750.0, (float) $commission->amount);
        $this->assertSame('active', $commission->status);
    }

    public function test_une_course_non_demarree_ne_peut_pas_etre_terminee(): void
    {
        $driver = Driver::factory()->create();
        $booking = Booking::factory()->confirmed($driver)->create();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Finalisation non autorisée.');

        app(CompleteBooking::class)($booking->id, $driver->id);
    }

    public function test_la_course_d_un_autre_agent_ne_peut_pas_etre_terminee(): void
    {
        $proprietaire = Driver::factory()->create();
        $autre = Driver::factory()->create();
        $booking = Booking::factory()->inProgress($proprietaire)->create();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Finalisation non autorisée.');

        app(CompleteBooking::class)($booking->id, $autre->id);
    }
}
