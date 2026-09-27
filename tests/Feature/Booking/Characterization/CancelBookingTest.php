<?php

namespace Tests\Feature\Booking\Characterization;

use App\Domains\Booking\Application\Actions\CancelBooking;
use App\Models\Booking;
use App\Models\Driver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Caractérisation de BookingService::cancel().
 *
 * ⚠️ Annuler CRÉE des courses. Trois cascades, et le CAS 2 se dédouble selon qu'il
 * existe ou non des enfants J2+ : sans enfants la course retour du J1 est supprimée
 * avant recréation, avec enfants elle survit. Les deux moitiés sont couvertes.
 */
class CancelBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_cas_1_enfant_d_abonnement_annule_et_recree_lie_au_titulaire(): void
    {
        // La course passe `cancelled` ET une copie `pending` est créée, qui conserve
        // subscription_driver_id — donc toujours l'agent A.
        $titulaire = Driver::factory()->create();
        $parent = Booking::factory()->subscriptionParent()->create();
        $enfant = Booking::factory()
            ->subscriptionChild($parent)
            ->linkedToSubscriptionDriver($titulaire)
            ->create(['driver_id' => $titulaire->id, 'status' => 'confirmed']);

        app(CancelBooking::class)($enfant->id, $titulaire->id, 'Panne de tricycle');

        $enfant->refresh();
        $this->assertSame('cancelled', $enfant->status);
        $this->assertSame('Panne de tricycle', $enfant->cancellation_reason);
        $this->assertNotNull($enfant->cancelled_at);

        $copie = Booking::where('parent_booking_id', $parent->id)
            ->where('status', 'pending')
            ->where('id', '!=', $enfant->id)
            ->first();

        $this->assertNotNull($copie, 'La copie de remplacement doit exister.');
        $this->assertSame($titulaire->id, $copie->subscription_driver_id);
        $this->assertNull($copie->driver_id);
        $this->assertSame($parent->id, $copie->parent_booking_id);
    }

    public function test_cas_2_parent_sans_enfants_supprime_puis_recree_la_course_retour(): void
    {
        $titulaire = Driver::factory()->create();
        $parent = Booking::factory()
            ->subscriptionParent()
            ->roundTrip('18:30')
            ->create(['driver_id' => $titulaire->id, 'status' => 'confirmed']);
        $ancienRetour = Booking::factory()->returnOf($parent)->create(['base_price' => 3000]);

        app(CancelBooking::class)($parent->id, $titulaire->id, 'Indisponible');

        $this->assertSame('cancelled', $parent->fresh()->status);
        // L'ancienne course retour a été supprimée : pas d'enfants J2+.
        $this->assertNull(Booking::find($ancienRetour->id));

        $nouveauParent = Booking::where('is_recurring', true)
            ->where('status', 'pending')
            ->where('id', '!=', $parent->id)
            ->first();

        $this->assertNotNull($nouveauParent);
        $this->assertNull($nouveauParent->subscription_driver_id, 'Le parent recréé est visible de tous.');

        $nouveauRetour = Booking::where('parent_booking_id', $nouveauParent->id)
            ->where('trip_type', 'return')
            ->first();

        $this->assertNotNull($nouveauRetour);
        // Le prix propre à la course retour est repris, pas celui du parent.
        $this->assertSame(3000.0, (float) $nouveauRetour->base_price);
        $this->assertNull($nouveauRetour->subscription_driver_id, 'La course retour reste cachée de tous.');
        $this->assertSame('18:30', substr((string) $nouveauRetour->pickup_time, 0, 5));
    }

    public function test_cas_2_parent_avec_enfants_conserve_la_course_retour_existante(): void
    {
        // L'autre moitié de la branche : `$hasChildren` vrai, donc pas de suppression.
        $titulaire = Driver::factory()->create();
        $parent = Booking::factory()
            ->subscriptionParent()
            ->roundTrip('18:30')
            ->create(['driver_id' => $titulaire->id, 'status' => 'confirmed']);
        $ancienRetour = Booking::factory()->returnOf($parent)->create();
        Booking::factory()->subscriptionChild($parent)->create();

        app(CancelBooking::class)($parent->id, $titulaire->id, 'Indisponible');

        $this->assertNotNull(Booking::find($ancienRetour->id), 'La course retour du J1 survit.');
    }

    public function test_cas_2_parent_sans_aller_retour_ne_recree_aucune_course_retour(): void
    {
        $titulaire = Driver::factory()->create();
        $parent = Booking::factory()
            ->subscriptionParent()
            ->create(['driver_id' => $titulaire->id, 'status' => 'confirmed']);

        app(CancelBooking::class)($parent->id, $titulaire->id, 'Indisponible');

        $this->assertSame(0, Booking::where('trip_type', 'return')->count());
    }

    public function test_cas_3_course_unique_annulee_et_recreee(): void
    {
        $driver = Driver::factory()->create();
        $booking = Booking::factory()->confirmed($driver)->create();

        app(CancelBooking::class)($booking->id, $driver->id, 'Client absent');

        $this->assertSame('cancelled', $booking->fresh()->status);

        $recreee = Booking::where('status', 'pending')->first();
        $this->assertNotNull($recreee);
        $this->assertNull($recreee->driver_id);
        $this->assertSame($booking->from_location, $recreee->from_location);
        // `days` et `remaining_days` sont FORCÉS à 1 dans cette branche, quels que
        // soient ceux de la course d'origine. Vérifié dans le code, pas supposé.
        $this->assertSame(1, $recreee->days);
    }

    public function test_cas_3_course_unique_aller_retour_recree_aussi_la_course_retour(): void
    {
        $driver = Driver::factory()->create();
        $aller = Booking::factory()->roundTrip('19:00')->confirmed($driver)->create();
        $ancienRetour = Booking::factory()->returnOf($aller)->create(['base_price' => 2500]);

        app(CancelBooking::class)($aller->id, $driver->id, 'Client absent');

        $this->assertNull(Booking::find($ancienRetour->id), 'L\'ancienne course retour est supprimée.');

        $nouvelAller = Booking::where('status', 'pending')->where('trip_type', 'go')->first();
        $nouveauRetour = Booking::where('trip_type', 'return')->first();

        $this->assertNotNull($nouveauRetour);
        $this->assertSame($nouvelAller->id, $nouveauRetour->parent_booking_id);
        $this->assertSame(2500.0, (float) $nouveauRetour->base_price);
        $this->assertNull($nouveauRetour->subscription_driver_id);
    }

    public function test_la_course_d_un_autre_agent_est_refusee(): void
    {
        $proprietaire = Driver::factory()->create();
        $autre = Driver::factory()->create();
        $booking = Booking::factory()->confirmed($proprietaire)->create();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Accès non autorisé.');

        app(CancelBooking::class)($booking->id, $autre->id, 'Peu importe');
    }

    public function test_une_course_terminee_ne_peut_plus_etre_annulee(): void
    {
        $driver = Driver::factory()->create();
        $booking = Booking::factory()->completed($driver)->create();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cette réservation ne peut plus être annulée.');

        app(CancelBooking::class)($booking->id, $driver->id, 'Trop tard');
    }
}
