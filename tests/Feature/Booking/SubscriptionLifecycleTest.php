<?php

namespace Tests\Feature\Booking;

use App\Models\Booking;
use App\Services\BookingService;
use App\Services\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Feature\Booking\Concerns\BuildsSubscriptions;
use Tests\TestCase;

/**
 * Le cycle de vie d'un abonnement, de sa génération quotidienne à sa fin.
 */
class SubscriptionLifecycleTest extends TestCase
{
    use BuildsSubscriptions;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * ⚠️ La génération de la DERNIÈRE course enfant passait le parent à
     * `is_recurring = false`. Il cessait alors d'être reconnu comme un abonnement — et
     * ses enfants avec lui : ils disparaissaient du récap des revenus du dossier agent,
     * et les courses du dernier jour devenaient visibles de tous les agents.
     */
    public function test_un_abonnement_arrive_au_bout_reste_un_abonnement(): void
    {
        $parent = $this->makeParent(['remaining_days' => 1]);

        Carbon::setTestNow('2026-09-28 01:00:05');
        app(BookingService::class)->createRecurringBookings();

        $parent->refresh();
        $child = Booking::where('parent_booking_id', $parent->id)->firstOrFail();

        $this->assertSame(0, $parent->remaining_days);
        $this->assertTrue($parent->is_subscription_parent);
        $this->assertTrue($child->is_subscription_child);
    }

    public function test_le_recap_des_revenus_garde_les_abonnements_termines(): void
    {
        $driver = $this->makeDriver();
        $parent = $this->makeParent([
            'remaining_days' => 1,
            'status' => 'completed',
            'driver_id' => $driver->id,
            'driver_earning' => 800,
        ]);

        Carbon::setTestNow('2026-09-28 01:00:05');
        app(BookingService::class)->createRecurringBookings();

        Booking::where('parent_booking_id', $parent->id)->update([
            'status' => 'completed',
            'driver_id' => $driver->id,
            'driver_earning' => 800,
        ]);

        $recap = app(CommissionService::class)->getDriverSubscriptionRevenue($driver->id);

        $this->assertCount(1, $recap['subscriptions']);
        $this->assertSame(2, $recap['subscriptions'][0]['bookings_count']);
        $this->assertSame(1600.0, $recap['total_due']);
    }

    /** La commande ne doit pas pour autant générer au-delà du nombre de jours payés. */
    public function test_un_abonnement_arrive_au_bout_ne_genere_plus_rien(): void
    {
        $parent = $this->makeParent(['remaining_days' => 1]);

        Carbon::setTestNow('2026-09-28 01:00:05');
        app(BookingService::class)->createRecurringBookings();
        Carbon::setTestNow('2026-09-29 01:00:05');
        app(BookingService::class)->createRecurringBookings();
        Carbon::setTestNow('2026-09-30 01:00:05');
        app(BookingService::class)->createRecurringBookings();

        $this->assertSame(1, Booking::where('parent_booking_id', $parent->id)->count());
    }

    public function test_la_migration_rend_leur_statut_aux_abonnements_deja_termines(): void
    {
        $finished = $this->makeParent(['remaining_days' => 0, 'is_recurring' => false]);
        $child = Booking::create(array_merge($finished->only([
            'base_price', 'total_price', 'pickup_time', 'from_location', 'to_location', 'distance', 'phone', 'days',
        ]), [
            'id' => (string) Str::uuid(),
            'booking_number' => 'CTT-'.strtoupper(Str::random(8)),
            'status' => 'completed',
            'pickup_date' => '2026-09-29',
            'trip_type' => 'go',
            'is_recurring' => false,
            'parent_booking_id' => $finished->id,
        ]));
        $simple = $this->makeParent(['days' => 1, 'remaining_days' => 1, 'is_recurring' => false]);

        $migration = require database_path('migrations/2026_09_25_100000_restore_is_recurring_on_finished_subscriptions.php');
        $migration->up();

        $this->assertTrue($finished->refresh()->is_recurring);
        $this->assertFalse($child->refresh()->is_recurring, 'un enfant garde is_recurring à false');
        $this->assertTrue($child->is_subscription_child);
        $this->assertFalse($simple->refresh()->is_recurring, 'une course simple n\'est pas un abonnement');
    }

    /**
     * ⚠️ La commande de 1h ne voit que les abonnements déjà acceptés. Accepté le jour même
     * de son démarrage, APRÈS 1h, un abonnement attendait le passage suivant — le jour J —
     * pour générer la course du lendemain : constaté en production.
     */
    public function test_accepte_apres_le_passage_de_1h_la_course_du_lendemain_est_generee_aussitot(): void
    {
        $parent = $this->makeParent(['status' => 'pending']);
        $driver = $this->makeDriver();

        Carbon::setTestNow('2026-09-28 01:00:05');
        app(BookingService::class)->createRecurringBookings();
        $this->assertSame(0, Booking::where('parent_booking_id', $parent->id)->count(), 'pas encore accepté, rien de généré');

        Carbon::setTestNow('2026-09-28 07:30:00');
        app(BookingService::class)->take($parent->id, $driver->id);

        $child = Booking::where('parent_booking_id', $parent->id)->firstOrFail();
        $this->assertSame('2026-09-29', $child->pickup_date->toDateString());
        $this->assertSame($driver->id, $child->subscription_driver_id);
        $this->assertSame(2, $parent->refresh()->remaining_days);
    }

    public function test_accepte_avant_le_passage_de_1h_rien_nest_genere_en_avance(): void
    {
        $parent = $this->makeParent(['status' => 'pending']);
        $driver = $this->makeDriver();

        Carbon::setTestNow('2026-09-27 15:00:00');
        app(BookingService::class)->take($parent->id, $driver->id);

        $this->assertSame(0, Booking::where('parent_booking_id', $parent->id)->count());
    }

    /** Le rattrapage ne génère pas deux fois la même journée si la commande repasse. */
    public function test_la_commande_ne_double_pas_une_journee_deja_rattrapee(): void
    {
        $parent = $this->makeParent(['status' => 'pending']);
        $driver = $this->makeDriver();

        Carbon::setTestNow('2026-09-28 07:30:00');
        app(BookingService::class)->take($parent->id, $driver->id);
        Carbon::setTestNow('2026-09-29 01:00:05');
        app(BookingService::class)->createRecurringBookings();

        $dates = Booking::where('parent_booking_id', $parent->id)->pluck('pickup_date')
            ->map(fn ($d) => $d->toDateString())->sort()->values()->all();
        $this->assertSame(['2026-09-29', '2026-09-30'], $dates);
    }
}
