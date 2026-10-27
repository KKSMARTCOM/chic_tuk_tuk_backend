<?php

namespace Tests\Feature\Notification;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Domains\Notification\Application\Notifier;
use App\Models\Booking;
use App\Models\Driver;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Kreait\Firebase\Contract\Messaging;
use Mockery;
use Tests\TestCase;

/**
 * La notification « Nouvelle réservation disponible » d'une course qui n'est plus libre
 * disparaît chez les agents (spec 2026-10-27-liste-des-notifications, §2).
 */
class StaleBookingNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $agentA;

    private User $agentB;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // Firebase n'est jamais joint : seule la trace en base compte.
        $this->app->instance(Messaging::class, Mockery::mock(Messaging::class)->shouldIgnoreMissing());
        $this->agentA = User::factory()->profil(Profil::Driver)->create(['password' => Hash::make('secret-123')]);
        $this->agentB = User::factory()->profil(Profil::Driver)->create(['password' => Hash::make('secret-123')]);
        $this->admin = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('secret-123')]);
    }

    private function as(User $user): self
    {
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret-123'])->json('token');
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function published(): Booking
    {
        $booking = Booking::factory()->create();
        app(Notifier::class)->bookingCreated($booking);

        return $booking;
    }

    /** @return list<string> */
    private function titles(User $user): array
    {
        return array_column($this->as($user)->getJson('/api/v1/notifications')->assertOk()->json('data'), 'title');
    }

    private function unread(User $user): int
    {
        return $this->as($user)->getJson('/api/v1/notifications')->json('unread_count');
    }

    private function takeBy(Booking $booking, User $agent): void
    {
        $driver = Driver::factory()->create(['user_id' => $agent->id]);
        $booking->update(['driver_id' => $driver->id, 'status' => 'confirmed']);
    }

    public function test_agents_receive_the_booking_id_and_admins_do_not(): void
    {
        $booking = $this->published();

        $agent = Notification::where('user_id', $this->agentA->id)->sole();
        $this->assertSame('booking_available', $agent->data['kind']);
        $this->assertSame($booking->id, $agent->data['booking_id']);
        $admin = Notification::where('user_id', $this->admin->id)->sole();
        $this->assertArrayNotHasKey('kind', $admin->data);
        $this->assertArrayNotHasKey('booking_id', $admin->data);
    }

    public function test_a_free_booking_keeps_its_notification(): void
    {
        $this->published();

        $this->assertSame(['Nouvelle réservation disponible'], $this->titles($this->agentA));
        $this->assertSame(1, $this->unread($this->agentA));
    }

    public function test_the_notification_disappears_once_the_booking_is_taken(): void
    {
        $booking = $this->published();
        $this->takeBy($booking, $this->agentA);

        foreach ([$this->agentA, $this->agentB] as $agent) {
            $this->assertSame([], $this->titles($agent));
            $this->assertSame(0, $this->unread($agent));
        }
    }

    public function test_cancelled_expired_and_deleted_bookings_hide_it(): void
    {
        $this->published()->update(['status' => 'cancelled']);
        $this->published()->update(['status' => 'expired']);
        $this->published()->delete();

        $this->assertSame([], $this->titles($this->agentB));
        $this->assertSame(0, $this->unread($this->agentB));
    }

    public function test_it_comes_back_when_the_booking_is_free_again(): void
    {
        $booking = $this->published();
        Notification::where('user_id', $this->agentB->id)->update(['is_read' => true]);
        $this->takeBy($booking, $this->agentA);
        $this->assertSame([], $this->titles($this->agentB));

        $booking->update(['driver_id' => null, 'status' => 'pending']);

        $list = $this->as($this->agentB)->getJson('/api/v1/notifications')->json('data');
        $this->assertSame(['Nouvelle réservation disponible'], array_column($list, 'title'));
        $this->assertTrue($list[0]['is_read']);
    }

    public function test_old_and_odd_payloads_never_break_the_list(): void
    {
        $make = fn (string $title, ?array $data) => Notification::create([
            'user_id' => $this->agentA->id, 'title' => $title, 'message' => 'm', 'type' => 'info', 'data' => $data,
        ]);
        $make('ancienne', ['type' => 'info', 'url' => '/driver/bookings/available']);
        $make('sans charge', null);
        $make('sans course', ['kind' => 'booking_available']);
        $make('texte', ['kind' => 'booking_available', 'booking_id' => 'pas-un-uuid']);
        $make('nombre', ['kind' => 'booking_available', 'booking_id' => 42]);

        $titles = $this->titles($this->agentA);
        sort($titles);

        $this->assertSame(['ancienne', 'sans charge', 'sans course'], $titles);
    }

    public function test_admin_notifications_are_never_hidden(): void
    {
        $booking = Booking::factory()->create(['status' => 'cancelled']);
        Notification::create([
            'user_id' => $this->admin->id, 'title' => 'admin', 'message' => 'm', 'type' => 'info',
            'data' => ['kind' => 'booking_available', 'booking_id' => $booking->id],
        ]);

        $this->assertSame(['admin'], $this->titles($this->admin));
    }
}
