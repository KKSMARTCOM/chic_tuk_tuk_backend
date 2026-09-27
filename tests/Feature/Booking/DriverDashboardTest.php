<?php

namespace Tests\Feature\Booking;

use App\Domains\Booking\Application\Actions\BuildDriverDashboard;
use App\Models\Booking;
use App\Models\Driver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function jeuDeDonnees(): Driver
    {
        $driver = Driver::factory()->create(['total_trips' => 12, 'rating' => 4.5]);

        Booking::factory()->count(2)->confirmed($driver)->create();
        Booking::factory()->count(3)->cancelled($driver)->create();
        Booking::factory()->completed($driver)->create([
            'completed_at' => now(),
            'started_at' => now()->subMinutes(45),
            'driver_earning' => 4250,
            'commission' => 750,
        ]);
        Booking::factory()->completed($driver)->create([
            'completed_at' => now()->subDays(3),
            'started_at' => now()->subDays(3)->subMinutes(15),
            'driver_earning' => 3000,
            'commission' => 500,
        ]);
        Booking::factory()->count(6)->create(); // des courses libres, pour l'aperçu

        return $driver;
    }

    public function test_les_compteurs_sont_ceux_du_service_actuel(): void
    {
        $driver = $this->jeuDeDonnees();

        // Les valeurs que rendait `DriverService::getDriverDashboardStats()` sur ce jeu,
        // relevées le 2026-09-27 avant sa suppression avec le Blade.
        $ancien = [
            'total_trips' => 12, 'rating' => '4.50', 'confirmed_trips' => 2, 'completed_trips' => 2,
            'cancelled_trips' => 3, 'earnings_today' => '4250.00', 'total_earnings' => '7250.00',
            'commission_today' => '750.00', 'total_commission' => '1250.00', 'total_duration_minutes' => 60,
        ];
        $nouveau = app(BuildDriverDashboard::class)($driver);

        foreach ([
            'total_trips', 'rating', 'confirmed_trips', 'completed_trips',
            'cancelled_trips', 'earnings_today', 'total_earnings',
            'commission_today', 'total_commission', 'total_duration_minutes',
        ] as $cle) {
            $this->assertEquals(
                $ancien[$cle],
                $nouveau[$cle],
                "Le compteur « {$cle} » diverge de l'implémentation actuelle.",
            );
        }
    }

    public function test_les_gains_du_jour_excluent_les_courses_des_jours_precedents(): void
    {
        // Sans ce test, le précédent passerait encore si les deux implémentations
        // sommaient tout : il compare deux résultats, pas un résultat à une vérité.
        $driver = $this->jeuDeDonnees();

        $stats = app(BuildDriverDashboard::class)($driver);

        $this->assertEquals(4250, $stats['earnings_today']);
        $this->assertEquals(7250, $stats['total_earnings']);
    }

    public function test_les_apercus_sont_limites_a_cinq_elements(): void
    {
        $driver = $this->jeuDeDonnees();

        $stats = app(BuildDriverDashboard::class)($driver);

        $this->assertCount(5, $stats['recent_available']);
        $this->assertLessThanOrEqual(5, count($stats['recent_assigned']));
    }

    public function test_l_apercu_des_courses_acceptees_ne_retient_que_les_confirmees(): void
    {
        // Le Blade filtre sur `confirmed` SEUL, pas sur ['confirmed', 'in_progress'].
        $driver = Driver::factory()->create();
        $confirmee = Booking::factory()->confirmed($driver)->create();
        Booking::factory()->inProgress($driver)->create();

        $stats = app(BuildDriverDashboard::class)($driver);

        $this->assertSame([$confirmee->id], collect($stats['recent_assigned'])->pluck('id')->all());
    }

    public function test_la_duree_totale_est_en_minutes_entieres(): void
    {
        $driver = $this->jeuDeDonnees();

        $stats = app(BuildDriverDashboard::class)($driver);

        // 45 + 15 minutes de courses terminées.
        $this->assertEquals(60, $stats['total_duration_minutes']);
    }
}
