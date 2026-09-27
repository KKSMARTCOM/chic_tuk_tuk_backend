<?php

namespace Tests\Feature\Booking\Characterization;

use App\Domains\Booking\Application\Actions\StartBooking;
use App\Models\Booking;
use App\Models\Driver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Caractérisation de BookingService::start().
 *
 * ⚠️ Trois refus, dont deux se ressemblent. « Vous avez déjà une course en cours » vient
 * d'une requête sur status = 'in_progress' ; « Vous devez terminer ou annuler toutes les
 * courses précédentes » vient de Driver::hasBlockingPreviousBookings(), qui compare
 * CONCAT(pickup_date, ' ', pickup_time). Une course ANTÉRIEURE encore `confirmed` suffit
 * à bloquer, sans qu'aucune course ne soit en cours.
 */
class StartBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_course_confirmee_passe_in_progress_et_date_son_depart(): void
    {
        $driver = Driver::factory()->create();
        $booking = Booking::factory()->confirmed($driver)->create();

        app(StartBooking::class)($booking->id, $driver->id);

        $booking->refresh();
        $this->assertSame('in_progress', $booking->status);
        $this->assertNotNull($booking->started_at);
    }

    public function test_la_course_d_un_autre_agent_est_refusee(): void
    {
        $proprietaire = Driver::factory()->create();
        $autre = Driver::factory()->create();
        $booking = Booking::factory()->confirmed($proprietaire)->create();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Démarrage non autorisé.');

        app(StartBooking::class)($booking->id, $autre->id);
    }

    public function test_une_course_deja_en_cours_bloque_le_demarrage_d_une_autre(): void
    {
        // Premier refus : une course `in_progress` existe pour cet agent.
        $driver = Driver::factory()->create();
        Booking::factory()->inProgress($driver)->create([
            'pickup_date' => now()->addDays(2)->toDateString(),
        ]);
        $suivante = Booking::factory()->confirmed($driver)->create([
            'pickup_date' => now()->addDays(3)->toDateString(),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Vous avez déjà une course en cours.');

        app(StartBooking::class)($suivante->id, $driver->id);
    }

    public function test_une_course_d_un_jour_anterieur_non_soldee_bloque_le_demarrage(): void
    {
        // Second refus, DISTINCT du premier : aucune course n'est en cours, mais une
        // course d'un jour antérieur reste `confirmed`.
        $driver = Driver::factory()->create();
        Booking::factory()->confirmed($driver)->create([
            'pickup_date' => now()->addDay()->toDateString(),
            'pickup_time' => '07:00',
        ]);
        $visee = Booking::factory()->confirmed($driver)->create([
            'pickup_date' => now()->addDays(2)->toDateString(),
            'pickup_time' => '10:00',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Vous devez terminer ou annuler toutes les courses précédentes avant de démarrer celle-ci.');

        app(StartBooking::class)($visee->id, $driver->id);
    }

    public function test_une_course_anterieure_du_meme_jour_bloque_aussi_le_demarrage(): void
    {
        // Ce cas ne passait PAS avant le 2026-09-18 : hasBlockingPreviousBookings()
        // comparait une chaîne rendue par PostgreSQL — « 2026-09-19 07:00:00 » — à une
        // chaîne rendue par PHP depuis un Carbon — « 2026-09-19 00:00:00 10:00 ». La
        // comparaison lexicographique butait au douzième caractère ('7' > '0'), et une
        // course du même jour n'était jamais vue comme antérieure.
        //
        // Un agent pouvait donc démarrer sa course de 10:00 en laissant celle de 07:00
        // en plan — exactement ce que ce refus existe pour empêcher. Corrigé.
        $driver = Driver::factory()->create();
        Booking::factory()->confirmed($driver)->create([
            'pickup_date' => now()->addDay()->toDateString(),
            'pickup_time' => '07:00',
        ]);
        $visee = Booking::factory()->confirmed($driver)->create([
            'pickup_date' => now()->addDay()->toDateString(),
            'pickup_time' => '10:00',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Vous devez terminer ou annuler toutes les courses précédentes avant de démarrer celle-ci.');

        app(StartBooking::class)($visee->id, $driver->id);
    }

    public function test_une_course_posterieure_du_meme_jour_ne_bloque_pas(): void
    {
        // Le garde-fou du correctif : sans lui, une comparaison trop large bloquerait
        // tout, et les tests de refus ci-dessus passeraient encore — pour la mauvaise
        // raison.
        $driver = Driver::factory()->create();
        Booking::factory()->confirmed($driver)->create([
            'pickup_date' => now()->addDay()->toDateString(),
            'pickup_time' => '17:00',
        ]);
        $visee = Booking::factory()->confirmed($driver)->create([
            'pickup_date' => now()->addDay()->toDateString(),
            'pickup_time' => '10:00',
        ]);

        app(StartBooking::class)($visee->id, $driver->id);

        $this->assertSame('in_progress', $visee->fresh()->status);
    }

    public function test_la_course_visee_ne_se_bloque_pas_elle_meme(): void
    {
        // La course visée figure dans $driver->bookings() et porte exactement le même
        // repère qu'elle-même. C'est la comparaison STRICTE qui l'exclut ; passer à
        // `<=` ferait qu'aucune course ne pourrait plus jamais démarrer.
        $driver = Driver::factory()->create();
        $visee = Booking::factory()->confirmed($driver)->create([
            'pickup_date' => now()->addDay()->toDateString(),
            'pickup_time' => '10:00',
        ]);

        app(StartBooking::class)($visee->id, $driver->id);

        $this->assertSame('in_progress', $visee->fresh()->status);
    }
}
