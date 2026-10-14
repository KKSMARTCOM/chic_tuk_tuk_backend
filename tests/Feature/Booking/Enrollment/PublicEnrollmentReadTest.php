<?php

namespace Tests\Feature\Booking\Enrollment;

use App\Models\Booking;
use App\Models\SubscriptionEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Spec 2026-10-14, §4.1 et §5.1 (GET). */
class PublicEnrollmentReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_link_shows_the_booking_reminder_and_nothing_more(): void
    {
        $parent = Booking::factory()->subscriptionParent()->create([
            'from_location' => 'Cadjehoun', 'to_location' => 'Fidjrosse', 'pickup_time' => '07:00',
            'round_trip' => true, 'return_time' => '17:30', 'phone' => '+22997000000',
        ]);
        $enrollment = SubscriptionEnrollment::factory()->for($parent, 'booking')->create();

        $this->getJson("/api/v1/public/enrollments/{$enrollment->token}")
            ->assertOk()
            ->assertJsonPath('booking.booking_number', $parent->booking_number)
            ->assertJsonPath('booking.from_location', 'Cadjehoun')
            ->assertJsonPath('booking.pickup_time', '07:00')
            ->assertJsonPath('booking.return_time', '17:30')
            ->assertJsonPath('booking.week_days', 'lun_ven')
            ->assertJsonPath('phone', '+22997000000')
            ->assertJsonMissingPath('booking.total_price')
            ->assertJsonMissingPath('booking.driver_id');
    }

    public function test_a_malformed_token_is_a_404_without_any_query(): void
    {
        foreach (['abc', str_repeat('a', 63), str_repeat('a', 63).'!', str_repeat('a', 65)] as $token) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->getJson('/api/v1/public/enrollments/'.rawurlencode($token))->assertNotFound();
            $this->assertSame([], collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'subscription_enrollments'))->all());
        }
    }

    public function test_an_unknown_token_is_a_404(): void
    {
        $this->getJson('/api/v1/public/enrollments/'.SubscriptionEnrollment::newToken())->assertNotFound();
    }

    public function test_a_closed_link_is_a_410_with_its_reason(): void
    {
        $expired = SubscriptionEnrollment::factory()->expired()->create();
        $sent = SubscriptionEnrollment::factory()->submittedSchool()->create();
        $cancelled = SubscriptionEnrollment::factory()->for(Booking::factory()->subscriptionParent()->state(['status' => 'cancelled']), 'booking')->create();

        $this->getJson("/api/v1/public/enrollments/{$expired->token}")->assertStatus(410)->assertJsonPath('code', 'ENROLLMENT_LINK_EXPIRED');
        $this->getJson("/api/v1/public/enrollments/{$sent->token}")->assertStatus(410)->assertJsonPath('code', 'ENROLLMENT_ALREADY_SUBMITTED');
        $this->getJson("/api/v1/public/enrollments/{$cancelled->token}")->assertStatus(410)->assertJsonPath('code', 'ENROLLMENT_BOOKING_CLOSED');
    }
}
