<?php

namespace Tests\Feature\Console;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Booking;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** La purge de nuit des notifications (spec 2026-10-27-liste-des-notifications, §4). */
class PurgeNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = User::factory()->profil(Profil::Driver)->create();
    }

    private function notify(string $title, bool $read, int $daysAgo = 0, ?array $data = null): void
    {
        $n = Notification::create(['user_id' => $this->agent->id, 'title' => $title, 'message' => 'm', 'type' => 'info', 'is_read' => $read, 'data' => $data]);
        $n->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
    }

    private function about(Booking $booking): array
    {
        return ['kind' => 'booking_available', 'booking_id' => $booking->id];
    }

    /** @return list<string> */
    private function remaining(): array
    {
        return Notification::orderBy('title')->pluck('title')->all();
    }

    public function test_read_notifications_older_than_90_days_are_deleted(): void
    {
        $this->notify('lue à 91 jours', read: true, daysAgo: 91);
        $this->notify('lue à 89 jours', read: true, daysAgo: 89);
        $this->notify('non lue à 200 jours', read: false, daysAgo: 200);

        $this->artisan('app:purge-notifications')->expectsOutputToContain('1 notification(s) supprimée(s)')->assertSuccessful();

        $this->assertSame(['lue à 89 jours', 'non lue à 200 jours'], $this->remaining());
    }

    public function test_notifications_of_finished_or_deleted_bookings_are_deleted(): void
    {
        foreach (['completed', 'cancelled', 'missed'] as $status) {
            $this->notify("course {$status}", read: false, data: $this->about(Booking::factory()->create(['status' => $status])));
        }
        $deleted = Booking::factory()->create();
        $this->notify('course supprimée', read: false, data: $this->about($deleted));
        $deleted->delete();
        $this->notify('course acceptée', read: false, data: $this->about(Booking::factory()->create(['status' => 'confirmed'])));
        $this->notify('course libre', read: false, data: $this->about(Booking::factory()->create()));

        $this->artisan('app:purge-notifications')->assertSuccessful();

        $this->assertSame(['course acceptée', 'course libre'], $this->remaining());
    }

    public function test_it_runs_every_night(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('app:purge-notifications')->assertSuccessful();
    }
}
