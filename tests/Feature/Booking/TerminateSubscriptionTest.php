<?php

namespace Tests\Feature\Booking;

use App\Models\Booking;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\FcmNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Booking\Concerns\BuildsSubscriptions;
use Tests\TestCase;

/**
 * La résiliation d'un abonnement depuis la fiche d'administration.
 *
 * Le parent EST la course du premier jour : l'annuler une fois ce jour conduit le sortait
 * du revenu d'abonnement de l'agent. Résilier arrête l'abonnement sans réécrire ce qui a
 * eu lieu.
 */
class TerminateSubscriptionTest extends TestCase
{
    use BuildsSubscriptions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(FcmNotificationService::class)->shouldIgnoreMissing();
    }

    private function admin(): User
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => Str::uuid().'@example.test',
            'phone' => '91'.random_int(100000, 999999),
            'profil' => 'admin',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);
        $admin->givePermissionTo(Permission::firstOrCreate(['name' => 'edit-bookings', 'guard_name' => 'web']));

        return $admin;
    }

    private function cancel(Booking $booking, ?string $reason = null)
    {
        return $this->actingAs($this->admin())->postJson(
            "/admin/bookings/{$booking->id}/update-status",
            array_filter(['status' => 'cancelled', 'cancellation_reason' => $reason])
        );
    }

    public function test_terminating_keeps_the_first_day_already_driven(): void
    {
        $a = $this->makeDriver();
        $parent = $this->makeParent([
            'status' => 'completed', 'driver_id' => $a->id, 'subscription_driver_id' => $a->id,
            'remaining_days' => 3, 'makeup_go_count' => 1,
        ]);
        $done = $this->makeChild($parent, ['status' => 'completed', 'driver_id' => $a->id]);
        $accepted = $this->makeChild($parent, ['status' => 'confirmed', 'driver_id' => $a->id, 'pickup_date' => '2026-09-30']);
        $waiting = $this->makeChild($parent, ['pickup_date' => '2026-10-01']);

        $this->cancel($parent, 'Le client arrête')
            ->assertOk()
            ->assertJsonPath('message', 'Abonnement résilié avec succès');

        $parent->refresh();
        $this->assertSame('completed', $parent->status, 'le premier jour reste fait');
        $this->assertSame(0, $parent->remaining_days);
        $this->assertSame(0, $parent->makeup_go_count);

        $this->assertSame('completed', $done->refresh()->status);
        foreach ([$accepted, $waiting] as $child) {
            $child->refresh();
            $this->assertSame('cancelled', $child->status);
            $this->assertSame('Le client arrête', $child->cancellation_reason);
        }

        // Le premier jour, conduit, reste dans le revenu d'abonnement de l'agent.
        $revenue = app(CommissionService::class)->getDriverSubscriptionRevenue($a->id);
        $this->assertSame(2, $revenue['subscriptions']->first()['bookings_count']);
    }

    public function test_a_terminated_subscription_generates_no_more_days(): void
    {
        $a = $this->makeDriver();
        $parent = $this->makeParent([
            'status' => 'completed', 'driver_id' => $a->id, 'subscription_driver_id' => $a->id,
            'next_recurring_date' => now()->subHour(),
        ]);

        $this->cancel($parent)->assertOk();

        $this->artisan('app:process-recurring-bookings');
        $this->assertSame(0, Booking::where('parent_booking_id', $parent->id)->count());
    }

    public function test_a_first_day_still_to_come_cancels_everything(): void
    {
        $a = $this->makeDriver();
        $parent = $this->makeParent(['status' => 'confirmed', 'driver_id' => $a->id, 'subscription_driver_id' => $a->id]);
        $waiting = $this->makeChild($parent);

        $this->cancel($parent, 'Le client renonce')
            ->assertOk()
            ->assertJsonPath('message', 'Statut mis à jour avec succès');

        $this->assertSame('cancelled', $parent->refresh()->status);
        $waiting->refresh();
        $this->assertSame('cancelled', $waiting->status);
        $this->assertSame('Le client renonce', $waiting->cancellation_reason, 'le motif saisi atteint les courses');
    }

    /** Remettre un parent à « Acceptée » ne doit pas annuler ses courses à venir. */
    public function test_a_status_change_other_than_cancel_leaves_the_children(): void
    {
        $parent = $this->makeParent(['status' => 'pending']);
        $waiting = $this->makeChild($parent);

        $this->actingAs($this->admin())
            ->postJson("/admin/bookings/{$parent->id}/update-status", ['status' => 'confirmed'])
            ->assertOk();

        $this->assertSame('pending', $waiting->refresh()->status);
    }
}
