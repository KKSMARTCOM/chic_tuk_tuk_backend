<?php

namespace Tests\Feature\Booking;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Domains\Notification\Application\PushSender;
use App\Models\Booking;
use App\Models\User;
use App\Services\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Booking\Concerns\BuildsSubscriptions;
use Tests\TestCase;

/**
 * Transférer et résilier un abonnement depuis l'administration — deux gestes livrés en
 * production par le Blade (le transfert le 2026-09-25) et reportés dans l'API le
 * 2026-09-27.
 *
 * ⚠️ La résiliation d'un abonnement dont le premier jour est déjà FAIT (ou non traité) ne
 * réécrit plus son statut. Le Blade passait le parent — qui EST la course du premier
 * jour — à « Annulée » : ce jour sortait alors du revenu d'abonnement de l'agent, calculé
 * sur les courses terminées, alors qu'il l'avait bel et bien conduit.
 */
class AdminSubscriptionApiTest extends TestCase
{
    use BuildsSubscriptions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(PushSender::class)->shouldIgnoreMissing();
    }

    private function login(array $permissions = ['view-bookings', 'edit-bookings']): string
    {
        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'bon-mot-de-passe',
        ])->json('token');
    }

    private function asBearer(string $token): self
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    // ----- Transfert -------------------------------------------------------------------

    public function test_the_detail_offers_the_transfer_of_a_taken_subscription(): void
    {
        $token = $this->login();
        $a = $this->makeDriver();
        $taken = $this->makeParent(['status' => 'completed', 'driver_id' => $a->id, 'subscription_driver_id' => $a->id]);
        $free = $this->makeParent();
        $over = $this->makeParent(['status' => 'completed', 'driver_id' => $a->id, 'subscription_driver_id' => $a->id, 'remaining_days' => 0]);

        $this->asBearer($token)->getJson("/api/v1/admin/bookings/{$taken->id}")
            ->assertOk()
            ->assertJsonPath('can_transfer_subscription', true)
            ->assertJsonPath('subscription_driver_id', $a->id);
        $this->asBearer($token)->getJson("/api/v1/admin/bookings/{$free->id}")
            ->assertJsonPath('can_transfer_subscription', false);
        $this->asBearer($token)->getJson("/api/v1/admin/bookings/{$over->id}")
            ->assertJsonPath('can_transfer_subscription', false);

        // Une course enfant ne se transfère pas seule : c'est l'abonnement qui change de titulaire.
        $child = $this->makeChild($taken);
        $this->asBearer($token)->getJson("/api/v1/admin/bookings/{$child->id}")
            ->assertOk()
            ->assertJsonPath('can_transfer_subscription', false);
    }

    public function test_a_subscription_is_transferred_to_another_agent(): void
    {
        $token = $this->login();
        $a = $this->makeDriver();
        $b = $this->makeDriver();
        $parent = $this->makeParent(['status' => 'completed', 'driver_id' => $a->id, 'subscription_driver_id' => $a->id]);
        $accepted = $this->makeChild($parent, ['status' => 'confirmed', 'driver_id' => $a->id]);

        $this->asBearer($token)->postJson("/api/v1/admin/bookings/{$parent->id}/transfer-subscription", ['driver_id' => $b->id])
            ->assertOk()
            ->assertJsonPath('subscription_driver_id', $b->id);

        $this->assertSame($b->id, $accepted->refresh()->driver_id);
    }

    public function test_the_refusals_carry_a_code(): void
    {
        $token = $this->login();
        $a = $this->makeDriver();
        $parent = $this->makeParent(['status' => 'completed', 'driver_id' => $a->id, 'subscription_driver_id' => $a->id]);
        $free = $this->makeParent();

        $this->asBearer($token)->postJson("/api/v1/admin/bookings/{$parent->id}/transfer-subscription", ['driver_id' => $a->id])
            ->assertStatus(409)->assertJsonPath('code', 'SUBSCRIPTION_SAME_HOLDER');
        $this->asBearer($token)->postJson("/api/v1/admin/bookings/{$free->id}/transfer-subscription", ['driver_id' => $a->id])
            ->assertStatus(409)->assertJsonPath('code', 'SUBSCRIPTION_WITHOUT_HOLDER');
        $this->asBearer($token)->postJson("/api/v1/admin/bookings/{$parent->id}/transfer-subscription", [])
            ->assertUnprocessable()->assertJsonValidationErrors(['driver_id']);
    }

    public function test_transferring_requires_edit_bookings(): void
    {
        $token = $this->login(['view-bookings']);
        $a = $this->makeDriver();
        $b = $this->makeDriver();
        $parent = $this->makeParent(['status' => 'completed', 'driver_id' => $a->id, 'subscription_driver_id' => $a->id]);

        $this->asBearer($token)->postJson("/api/v1/admin/bookings/{$parent->id}/transfer-subscription", ['driver_id' => $b->id])
            ->assertForbidden();
    }

    // ----- Résiliation -----------------------------------------------------------------

    public function test_the_detail_offers_to_terminate_a_running_subscription_whose_first_day_is_done(): void
    {
        $token = $this->login();
        $a = $this->makeDriver();
        $running = $this->makeParent(['status' => 'completed', 'driver_id' => $a->id, 'subscription_driver_id' => $a->id]);
        $missedWithMakeup = $this->makeParent(['status' => 'missed', 'remaining_days' => 1, 'makeup_go_count' => 1]);
        $lastDay = $this->makeParent(['status' => 'completed', 'driver_id' => $a->id, 'remaining_days' => 1]);
        $notStarted = $this->makeParent(['status' => 'confirmed', 'driver_id' => $a->id]);

        foreach ([[$running, true], [$missedWithMakeup, true], [$lastDay, false], [$notStarted, false]] as [$booking, $expected]) {
            $this->asBearer($token)->getJson("/api/v1/admin/bookings/{$booking->id}")
                ->assertJsonPath('can_terminate_subscription', $expected);
        }
    }

    public function test_terminating_stops_the_subscription_and_keeps_the_day_already_driven(): void
    {
        $token = $this->login();
        $a = $this->makeDriver();
        $parent = $this->makeParent([
            'status' => 'completed', 'driver_id' => $a->id, 'subscription_driver_id' => $a->id,
            'remaining_days' => 3, 'makeup_go_count' => 1,
        ]);
        $done = $this->makeChild($parent, ['status' => 'completed', 'driver_id' => $a->id]);
        $accepted = $this->makeChild($parent, ['status' => 'confirmed', 'driver_id' => $a->id, 'pickup_date' => '2026-09-30']);
        $waiting = $this->makeChild($parent, ['pickup_date' => '2026-10-01']);

        $this->asBearer($token)->postJson("/api/v1/admin/bookings/{$parent->id}/terminate-subscription", [
            'cancellation_reason' => 'Le client arrête',
        ])->assertOk()->assertJsonPath('can_terminate_subscription', false);

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
        // Le Blade l'en sortait : 1 course au lieu de 2.
        $revenue = app(CommissionService::class)->getDriverSubscriptionRevenue($a->id);
        $this->assertSame(2, $revenue['subscriptions']->first()['bookings_count']);
    }

    public function test_a_subscription_that_is_not_running_is_not_terminated(): void
    {
        $token = $this->login();
        $a = $this->makeDriver();
        $lastDay = $this->makeParent(['status' => 'completed', 'driver_id' => $a->id, 'remaining_days' => 1]);
        $unique = Booking::create(array_merge($this->makeParent()->getAttributes(), [
            'id' => (string) Str::uuid(), 'booking_number' => 'CTT-UNIQUE', 'is_recurring' => false,
        ]));

        $this->asBearer($token)->postJson("/api/v1/admin/bookings/{$lastDay->id}/terminate-subscription")
            ->assertStatus(409)->assertJsonPath('code', 'SUBSCRIPTION_NOT_RUNNING');
        $this->asBearer($token)->postJson("/api/v1/admin/bookings/{$unique->id}/terminate-subscription")
            ->assertStatus(409)->assertJsonPath('code', 'SUBSCRIPTION_NOT_RUNNING');
    }
}
