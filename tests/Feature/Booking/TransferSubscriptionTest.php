<?php

namespace Tests\Feature\Booking;

use App\Domains\Booking\Application\Actions\TransferSubscription;
use App\Models\Booking;
use App\Services\BookingService;
use App\Services\FcmNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\Feature\Booking\Concerns\BuildsSubscriptions;
use Tests\TestCase;

/**
 * Le transfert d'un abonnement d'un agent titulaire à un autre.
 *
 * Jusqu'ici, seul le titulaire pouvait libérer ses courses, en les révoquant une à une :
 * quand c'est justement lui qui ne tient plus ses engagements, l'administration doit
 * pouvoir changer le titulaire elle-même.
 */
class TransferSubscriptionTest extends TestCase
{
    use BuildsSubscriptions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(FcmNotificationService::class)->shouldIgnoreMissing();
    }

    private function transfer(Booking $parent, string $driverId): void
    {
        app(TransferSubscription::class)($parent->id, $driverId);
    }

    public function test_le_nouvel_agent_devient_titulaire_et_recupere_les_courses_a_venir(): void
    {
        $a = $this->makeDriver();
        $b = $this->makeDriver();
        $parent = $this->makeParent(['status' => 'completed', 'driver_id' => $a->id, 'subscription_driver_id' => $a->id]);

        $done = $this->makeChild($parent, ['status' => 'completed', 'driver_id' => $a->id, 'pickup_date' => '2026-09-29']);
        $inProgress = $this->makeChild($parent, ['status' => 'in_progress', 'driver_id' => $a->id, 'pickup_date' => '2026-09-30']);
        $accepted = $this->makeChild($parent, ['status' => 'confirmed', 'driver_id' => $a->id, 'pickup_date' => '2026-10-01']);
        $waiting = $this->makeChild($parent, ['pickup_date' => '2026-10-02']);
        $revoked = $this->makeChild($parent, [
            'pickup_date' => '2026-10-03', 'subscription_driver_id' => null, 'is_revoked' => true, 'revoked_at' => now(),
        ]);

        $this->transfer($parent, $b->id);

        $this->assertSame($b->id, $parent->refresh()->subscription_driver_id);
        $this->assertSame($a->id, $parent->driver_id, 'le J1 déjà fait reste à A');

        // Ce qui est fait ou en cours ne bouge pas : les revenus restent à qui a roulé.
        $this->assertSame($a->id, $done->refresh()->driver_id);
        $this->assertSame($a->id, $inProgress->refresh()->driver_id);

        // Déjà acceptée par A : passe à B, toujours acceptée (choix de l'utilisateur).
        $accepted->refresh();
        $this->assertSame('confirmed', $accepted->status);
        $this->assertSame($b->id, $accepted->driver_id);
        $this->assertSame($b->id, $accepted->subscription_driver_id);

        $this->assertSame($b->id, $waiting->refresh()->subscription_driver_id);

        // Révoquée par A mais pas encore reprise : elle revient au nouveau titulaire.
        $revoked->refresh();
        $this->assertSame($b->id, $revoked->subscription_driver_id);
        $this->assertFalse($revoked->is_revoked);
    }

    /** Une course révoquée par A et déjà reprise par un TROISIÈME agent reste à lui. */
    public function test_une_course_reprise_par_un_autre_agent_reste_a_lui(): void
    {
        $a = $this->makeDriver();
        $b = $this->makeDriver();
        $c = $this->makeDriver();
        $parent = $this->makeParent(['status' => 'completed', 'driver_id' => $a->id, 'subscription_driver_id' => $a->id]);
        $takenByC = $this->makeChild($parent, [
            'status' => 'confirmed', 'driver_id' => $c->id, 'subscription_driver_id' => null, 'is_revoked' => true,
        ]);

        $this->transfer($parent, $b->id);

        $this->assertSame($c->id, $takenByC->refresh()->driver_id);
    }

    /** J1 pas encore démarré : le parent lui-même passe à B. */
    public function test_un_parent_accepte_mais_pas_demarre_passe_aussi_a_b(): void
    {
        $a = $this->makeDriver();
        $b = $this->makeDriver();
        $parent = $this->makeParent(['status' => 'confirmed', 'driver_id' => $a->id, 'subscription_driver_id' => $a->id]);

        $this->transfer($parent, $b->id);

        $this->assertSame($b->id, $parent->refresh()->driver_id);
    }

    public function test_les_courses_generees_ensuite_vont_a_b(): void
    {
        $a = $this->makeDriver();
        $b = $this->makeDriver();
        $parent = $this->makeParent(['status' => 'completed', 'driver_id' => $a->id, 'subscription_driver_id' => $a->id]);

        $this->transfer($parent, $b->id);

        Carbon::setTestNow('2026-09-28 01:00:05');
        app(BookingService::class)->createRecurringBookings();
        Carbon::setTestNow();

        $this->assertSame($b->id, Booking::where('parent_booking_id', $parent->id)->firstOrFail()->subscription_driver_id);
    }

    public function test_le_nouvel_agent_est_notifie(): void
    {
        $a = $this->makeDriver();
        $b = $this->makeDriver();
        $parent = $this->makeParent(['status' => 'completed', 'driver_id' => $a->id, 'subscription_driver_id' => $a->id]);

        $fcm = Mockery::mock(FcmNotificationService::class);
        $fcm->shouldReceive('sendToUser')->once()->withArgs(fn ($user) => $user->id === $b->user_id);
        $this->app->instance(FcmNotificationService::class, $fcm);

        $this->transfer($parent, $b->id);
    }

    public function test_refuse_un_abonnement_sans_titulaire(): void
    {
        $parent = $this->makeParent(['status' => 'pending']);

        $this->expectExceptionMessage("Cet abonnement n'a pas encore de titulaire");
        $this->transfer($parent, $this->makeDriver()->id);
    }

    public function test_refuse_de_transferer_au_titulaire_actuel(): void
    {
        $a = $this->makeDriver();
        $parent = $this->makeParent(['status' => 'completed', 'driver_id' => $a->id, 'subscription_driver_id' => $a->id]);

        $this->expectExceptionMessage('déjà titulaire');
        $this->transfer($parent, $a->id);
    }

    public function test_refuse_une_course_qui_nest_pas_un_abonnement_parent(): void
    {
        $a = $this->makeDriver();
        $parent = $this->makeParent(['status' => 'completed', 'driver_id' => $a->id, 'subscription_driver_id' => $a->id]);
        $child = $this->makeChild($parent);

        $this->expectExceptionMessage('Seul un abonnement parent');
        app(TransferSubscription::class)($child->id, $this->makeDriver()->id);
    }

    public function test_refuse_un_agent_inactif(): void
    {
        $a = $this->makeDriver();
        $b = $this->makeDriver();
        $b->user->update(['is_active' => false]);
        $parent = $this->makeParent(['status' => 'completed', 'driver_id' => $a->id, 'subscription_driver_id' => $a->id]);

        $this->expectExceptionMessage("n'est pas disponible");
        $this->transfer($parent, $b->id);
    }
}
