<?php

namespace Tests\Feature\Booking\Characterization;

use App\Domains\Booking\Application\Actions\AcceptBooking;
use App\Models\Booking;
use App\Models\Driver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Caractérisation de BookingService::take() — le comportement RÉEL, pas le souhaité.
 *
 * Ces tests sont écrits avant tout déplacement et doivent repasser à l'identique après.
 * Ils ne décrivent donc pas ce que le code devrait faire : ils décrivent ce qu'il fait.
 *
 * ⚠️ Les assertions d'exception portent sur `\Exception::class` et le message, jamais sur
 * une classe concrète : la conversion en ApiException viendra plus tard, et ApiException
 * hérite de RuntimeException donc d'Exception. Écrits ainsi, ces tests continueront de
 * passer sans être retouchés.
 */
class TakeBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_course_unique_passe_confirmed_et_recoit_son_agent(): void
    {
        $driver = Driver::factory()->create();
        $booking = Booking::factory()->create();

        app(AcceptBooking::class)($booking->id, $driver->id);

        $booking->refresh();
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame($driver->id, $booking->driver_id);
    }

    public function test_abonnement_parent_sans_titulaire_pose_le_titulaire_et_le_propage_au_retour(): void
    {
        // subscription_driver_id est posé sur le parent ET propagé sur la course retour
        // d'abonnement, qui reste `pending`.
        $driver = Driver::factory()->create();
        $parent = Booking::factory()->subscriptionParent()->roundTrip()->create();
        $retour = Booking::factory()->returnOf($parent)->create();

        app(AcceptBooking::class)($parent->id, $driver->id);

        $this->assertSame($driver->id, $parent->fresh()->subscription_driver_id);
        $this->assertSame($driver->id, $retour->fresh()->subscription_driver_id);
        $this->assertSame('pending', $retour->fresh()->status);
        $this->assertNull($retour->fresh()->driver_id);
    }

    public function test_course_unique_aller_retour_lie_l_agent_a_la_course_retour_cachee(): void
    {
        // La course retour cachée devient visible pour cet agent seul, par
        // subscription_driver_id — et non par driver_id.
        $driver = Driver::factory()->create();
        $aller = Booking::factory()->roundTrip()->create();
        $retour = Booking::factory()->returnOf($aller)->create();

        app(AcceptBooking::class)($aller->id, $driver->id);

        $this->assertSame($driver->id, $retour->fresh()->subscription_driver_id);
        $this->assertSame('pending', $retour->fresh()->status);
    }

    public function test_une_course_deja_prise_est_refusee(): void
    {
        $premier = Driver::factory()->create();
        $second = Driver::factory()->create();
        $booking = Booking::factory()->confirmed($premier)->create();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Réservation déjà prise ou annulée.');

        app(AcceptBooking::class)($booking->id, $second->id);
    }

    public function test_un_abonnement_lie_a_un_autre_agent_est_refuse(): void
    {
        $titulaire = Driver::factory()->create();
        $autre = Driver::factory()->create();
        $parent = Booking::factory()
            ->subscriptionParent()
            ->linkedToSubscriptionDriver($titulaire)
            ->create();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cette course n\'est pas accessible.');

        app(AcceptBooking::class)($parent->id, $autre->id);
    }

    public function test_un_enfant_d_abonnement_lie_a_a_reste_acceptable_par_b(): void
    {
        // ⚠️ Ce test verrouille un ÉCART, pas une règle souhaitée. isVisibleToDriver()
        // renvoie true dès que !is_recurring, et un enfant d'abonnement porte
        // is_recurring = false. La requête de liste, elle, ne le montre qu'au
        // titulaire. Le déplacement ne doit rien réconcilier : documenter et dater.
        $titulaire = Driver::factory()->create();
        $autre = Driver::factory()->create();
        $parent = Booking::factory()->subscriptionParent()->create();
        $enfant = Booking::factory()
            ->subscriptionChild($parent)
            ->linkedToSubscriptionDriver($titulaire)
            ->create();

        app(AcceptBooking::class)($enfant->id, $autre->id);

        $this->assertSame('confirmed', $enfant->fresh()->status);
        $this->assertSame($autre->id, $enfant->fresh()->driver_id);
    }
}
