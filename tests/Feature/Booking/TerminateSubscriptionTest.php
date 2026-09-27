<?php

namespace Tests\Feature\Booking;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Booking;
use App\Models\User;
use App\Services\FcmNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Booking\Concerns\BuildsSubscriptions;
use Tests\TestCase;

/**
 * La résiliation et l'annulation d'un abonnement, par l'API d'administration.
 *
 * Reportés du chemin Blade le 2026-09-27, avant sa suppression : ces cas n'étaient
 * vérifiés que par `update-status`.
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

    private function asAdmin(): self
    {
        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        foreach (['view-bookings', 'edit-bookings'] as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])->json('token');
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_a_terminated_subscription_generates_no_more_days(): void
    {
        $a = $this->makeDriver();
        $parent = $this->makeParent([
            'status' => 'completed', 'driver_id' => $a->id, 'subscription_driver_id' => $a->id,
            'next_recurring_date' => now()->subHour(),
        ]);

        $this->asAdmin()->postJson("/api/v1/admin/bookings/{$parent->id}/terminate-subscription")->assertOk();

        $this->artisan('app:process-recurring-bookings');
        $this->assertSame(0, Booking::where('parent_booking_id', $parent->id)->count());
    }

    public function test_cancelling_a_first_day_still_to_come_cancels_everything_with_the_reason(): void
    {
        $a = $this->makeDriver();
        $parent = $this->makeParent(['status' => 'confirmed', 'driver_id' => $a->id, 'subscription_driver_id' => $a->id]);
        $waiting = $this->makeChild($parent);

        $this->asAdmin()->postJson("/api/v1/admin/bookings/{$parent->id}/status", [
            'status' => 'cancelled', 'cancellation_reason' => 'Le client renonce',
        ])->assertOk();

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

        $this->asAdmin()->postJson("/api/v1/admin/bookings/{$parent->id}/status", ['status' => 'confirmed']);

        $this->assertSame('pending', $waiting->refresh()->status);
    }
}
