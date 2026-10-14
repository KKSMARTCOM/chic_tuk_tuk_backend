<?php

namespace Tests\Feature\Booking\Enrollment;

use App\Domains\Booking\Application\Actions\CancelBooking;
use App\Models\Booking;
use App\Models\Driver;
use App\Models\SubscriptionEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Spec 2026-10-14, §4 (étapes 1, 2, 5) et §4.2. */
class EnrollmentCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['api.openrouteservice.org/*' => Http::response(['routes' => [['summary' => ['distance' => 12_000]]]], 200)]);
        config(['app.front_landing_url' => 'https://chictuktuk.com']);
    }

    private function booking(array $extra = []): array
    {
        return array_merge([
            'from_location' => 'Cadjehoun', 'to_location' => 'Fidjrosse',
            'from_lat' => 6.36, 'from_lng' => 2.38, 'to_lat' => 6.37, 'to_lng' => 2.35,
            'pickup_date' => now()->addDays(3)->toDateString(), 'pickup_time' => '08:00',
            'phone' => '+22997000000', 'terms_accepted' => true,
        ], $extra);
    }

    public function test_a_subscription_booked_online_comes_with_its_enrollment_link(): void
    {
        $response = $this->postJson('/api/v1/public/bookings', $this->booking(['days' => 20, 'week_days' => 'lun_ven', 'pickup_date' => now()->next('Monday')->toDateString()]))
            ->assertCreated();

        $parent = Booking::query()->where('is_recurring', true)->sole();
        $this->assertNotNull($parent->enrollment);
        $response->assertJsonPath('enrollment_url', "https://chictuktuk.com/inscription/{$parent->enrollment->token}");
    }

    public function test_a_single_ride_has_no_enrollment(): void
    {
        $this->postJson('/api/v1/public/bookings', $this->booking())
            ->assertCreated()
            ->assertJsonPath('enrollment_url', null);

        $this->assertSame(0, SubscriptionEnrollment::count());
    }

    public function test_the_enrollment_follows_the_parent_recreated_by_a_driver_cancellation(): void
    {
        $driver = Driver::factory()->create();
        $parent = Booking::factory()->subscriptionParent()->create(['status' => 'confirmed', 'driver_id' => $driver->id, 'subscription_driver_id' => $driver->id]);
        $enrollment = SubscriptionEnrollment::factory()->for($parent, 'booking')->create();

        app(CancelBooking::class)($parent->id, $driver->id, 'Empêchement');

        $newParent = Booking::query()->where('is_recurring', true)->where('status', 'pending')->sole();
        $this->assertNotSame($parent->id, $newParent->id);
        $this->assertSame($newParent->id, $enrollment->fresh()->booking_id);
    }
}
