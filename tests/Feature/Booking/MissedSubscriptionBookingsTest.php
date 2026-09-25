<?php

namespace Tests\Feature\Booking;

use App\Models\Booking;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Feature\Booking\Concerns\BuildsSubscriptions;
use Tests\TestCase;

/**
 * Les courses enfants d'abonnement que personne n'a prises.
 *
 * Un client qui paie 10 jours doit recevoir 10 trajets (20 en aller-retour). Une course
 * enfant restée en attente 24h après son heure ne se perd donc plus en « expirée » : elle
 * passe « non traitée » (`missed`), et le trajet manqué — et lui seul — est rattrapé à la
 * fin de l'abonnement.
 */
class MissedSubscriptionBookingsTest extends TestCase
{
    use BuildsSubscriptions;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): BookingService
    {
        return app(BookingService::class);
    }

    public function test_une_course_enfant_en_attente_24h_apres_passe_non_traitee_et_se_rattrape(): void
    {
        // Il reste un jour normal, dont la génération est à venir : rien n'est dû maintenant.
        $parent = $this->makeParent(['remaining_days' => 2, 'next_recurring_date' => '2026-10-04 01:00:00']);
        $child = $this->makeChild($parent, ['pickup_date' => '2026-09-29']);

        Carbon::setTestNow('2026-09-30 09:00:00');
        $this->service()->markExpiredBookings();

        $this->assertSame('missed', $child->refresh()->status);
        $this->assertSame(1, $parent->refresh()->makeup_go_count);
        $this->assertSame(0, $parent->makeup_return_count);
    }

    public function test_une_course_simple_expire_toujours(): void
    {
        $simple = $this->makeParent([
            'days' => 1, 'remaining_days' => 1, 'is_recurring' => false,
            'status' => 'pending', 'next_recurring_date' => null, 'pickup_date' => '2026-09-28',
        ]);

        Carbon::setTestNow('2026-09-30 09:00:00');
        $this->service()->markExpiredBookings();

        $this->assertSame('expired', $simple->refresh()->status);
    }

    /** Un abonnement que personne n'a jamais accepté n'a rien à rattraper : il expire. */
    public function test_un_parent_jamais_accepte_expire_toujours(): void
    {
        $parent = $this->makeParent(['status' => 'pending']);

        Carbon::setTestNow('2026-09-30 09:00:00');
        $this->service()->markExpiredBookings();

        $this->assertSame('expired', $parent->refresh()->status);
    }

    /** Choix de l'utilisateur : seule une course EN ATTENTE devient non traitée. */
    public function test_une_course_acceptee_mais_pas_demarree_ne_change_pas(): void
    {
        $parent = $this->makeParent(['remaining_days' => 1]);
        $child = $this->makeChild($parent, ['status' => 'confirmed']);

        Carbon::setTestNow('2026-09-30 09:00:00');
        $this->service()->markExpiredBookings();

        $this->assertSame('confirmed', $child->refresh()->status);
        $this->assertSame(0, $parent->refresh()->makeup_go_count);
    }

    public function test_le_rattrapage_arrive_apres_les_jours_normaux(): void
    {
        // Trois journées restent, celle en cours comprise : lundi 28 (le parent), puis
        // mardi 29 et mercredi 30 à générer. Celle de mardi ne sera pas traitée.
        $parent = $this->makeParent(['remaining_days' => 3]);

        Carbon::setTestNow('2026-09-28 01:00:05');
        $this->service()->createRecurringBookings(); // mardi 29
        $tuesday = Booking::where('parent_booking_id', $parent->id)->firstOrFail();

        Carbon::setTestNow('2026-09-29 01:00:05');
        $this->service()->createRecurringBookings(); // mercredi 30 — dernier jour normal

        Carbon::setTestNow('2026-09-30 09:00:00');
        $this->service()->markExpiredBookings(); // mardi passe non traité

        Carbon::setTestNow('2026-10-01 01:00:05');
        $this->service()->createRecurringBookings(); // jeudi 1er : rattrapage

        Carbon::setTestNow('2026-10-02 01:00:05');
        $this->service()->createRecurringBookings(); // rien de plus

        $this->assertSame('missed', $tuesday->refresh()->status);

        $dates = Booking::where('parent_booking_id', $parent->id)->orderBy('pickup_date')
            ->pluck('pickup_date')->map(fn ($d) => $d->toDateString())->all();
        $this->assertSame(['2026-09-29', '2026-09-30', '2026-10-01'], $dates);

        $parent->refresh();
        $this->assertSame(0, $parent->makeup_go_count);
        $this->assertSame('2026-10-01', $parent->subscription_end_date->toDateString());
    }

    /**
     * Le cas qui interdit le « +1 jour » : un aller-retour dont seul le RETOUR a été
     * manqué ne doit rattraper que le retour, sinon le client reçoit un aller de trop.
     */
    public function test_un_retour_manque_ne_rattrape_que_le_retour(): void
    {
        $parent = $this->makeParent([
            'days' => 2, 'remaining_days' => 1, 'round_trip' => true, 'return_time' => '17:00:00',
            'next_recurring_date' => null, 'subscription_end_date' => '2026-09-29',
        ]);
        $this->makeChild($parent, ['pickup_date' => '2026-09-29', 'status' => 'completed']);
        $missedReturn = $this->makeChild($parent, [
            'pickup_date' => '2026-09-29', 'pickup_time' => '17:00:00', 'trip_type' => 'return',
        ]);

        // Le constat tombe au passage de 1h, comme en production.
        Carbon::setTestNow('2026-10-01 01:00:05');
        $this->service()->markExpiredBookings();
        $this->service()->createRecurringBookings(); // même minute : ne doit rien doubler

        $this->assertSame('missed', $missedReturn->refresh()->status);

        // L'abonnement était fini : le rattrapage prend le prochain jour autorisé, et sa
        // course est générée aussitôt — la veille, comme toute course d'abonnement.

        $makeup = Booking::where('parent_booking_id', $parent->id)
            ->whereDate('pickup_date', '2026-10-02')->get();

        $this->assertCount(1, $makeup);
        $this->assertSame('return', $makeup[0]->trip_type);
        $this->assertSame('17:00', substr($makeup[0]->pickup_time, 0, 5));
        $this->assertSame(0, $parent->refresh()->makeup_return_count);
    }

    public function test_la_reprise_convertit_seulement_les_abonnements_en_cours(): void
    {
        Carbon::setTestNow('2026-09-30 09:00:00');

        $ongoing = $this->makeParent(['remaining_days' => 2, 'next_recurring_date' => '2026-09-30 01:00:00']);
        $ongoingExpired = $this->makeChild($ongoing, ['status' => 'expired', 'expired_at' => now()]);

        $finished = $this->makeParent([
            'pickup_date' => '2026-09-01', 'remaining_days' => 1, 'next_recurring_date' => null,
            'booking_number' => 'CTT-'.strtoupper(Str::random(8)),
        ]);
        $this->makeChild($finished, ['pickup_date' => '2026-09-03', 'status' => 'completed']);
        $finishedExpired = $this->makeChild($finished, ['pickup_date' => '2026-09-02', 'status' => 'expired', 'expired_at' => now()]);

        $this->artisan('app:recover-missed-subscription-bookings', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame('expired', $ongoingExpired->refresh()->status, 'le mode essai ne touche à rien');

        $this->artisan('app:recover-missed-subscription-bookings')->assertSuccessful();

        $this->assertSame('missed', $ongoingExpired->refresh()->status);
        $this->assertSame(1, $ongoing->refresh()->makeup_go_count);
        $this->assertSame('expired', $finishedExpired->refresh()->status);
        $this->assertSame(0, $finished->refresh()->makeup_go_count);
    }
}
