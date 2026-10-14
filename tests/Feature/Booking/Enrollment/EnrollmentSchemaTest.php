<?php

namespace Tests\Feature\Booking\Enrollment;

use App\Models\Booking;
use App\Models\SubscriptionEnrollment;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Spec 2026-10-14, §3. */
class EnrollmentSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_enrollment_belongs_to_one_subscription_parent_with_its_link(): void
    {
        config(['app.front_landing_url' => 'https://chictuktuk.com']);
        $parent = Booking::factory()->subscriptionParent()->create();
        $enrollment = SubscriptionEnrollment::factory()->for($parent, 'booking')->create();

        $this->assertSame(64, strlen($enrollment->token));
        $this->assertTrue($enrollment->token_expires_at->between(now()->addDays(29), now()->addDays(31)));
        $this->assertSame("https://chictuktuk.com/inscription/{$enrollment->token}", $enrollment->url());
        $this->assertTrue($parent->enrollment->is($enrollment));
    }

    public function test_one_enrollment_per_booking_and_the_kind_is_checked(): void
    {
        $parent = Booking::factory()->subscriptionParent()->create();
        SubscriptionEnrollment::factory()->for($parent, 'booking')->create();

        $this->expectException(UniqueConstraintViolationException::class);
        SubscriptionEnrollment::factory()->for($parent, 'booking')->create();
    }

    public function test_a_wrong_kind_is_refused_by_the_database(): void
    {
        $enrollment = SubscriptionEnrollment::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('subscription_enrollments')->where('id', $enrollment->id)->update(['kind' => 'autre']);
    }

    public function test_the_people_come_back_in_their_order(): void
    {
        $enrollment = SubscriptionEnrollment::factory()->submittedSchool(2)->create();

        $this->assertSame([1, 2], $enrollment->people->pluck('position')->all());
        $this->assertNotNull($enrollment->people->first()->age);
    }

    public function test_the_migration_gives_running_subscriptions_an_empty_enrollment(): void
    {
        $running = Booking::factory()->subscriptionParent()->create();
        $runningAfterDayOne = Booking::factory()->subscriptionParent()->create(['status' => 'completed', 'remaining_days' => 10]);
        $finished = Booking::factory()->subscriptionParent()->create(['status' => 'completed', 'remaining_days' => 0, 'makeup_go_count' => 0, 'makeup_return_count' => 0]);
        $cancelled = Booking::factory()->subscriptionParent()->create(['status' => 'cancelled']);
        $single = Booking::factory()->create();

        $migration = require database_path('migrations/2026_10_14_110000_create_subscription_enrollments_tables.php');
        $migration->backfill();

        $this->assertNotNull($running->fresh()->enrollment);
        $this->assertNotNull($runningAfterDayOne->fresh()->enrollment);
        $this->assertNull($finished->fresh()->enrollment);
        $this->assertNull($cancelled->fresh()->enrollment);
        $this->assertNull($single->fresh()->enrollment);

        $migration->backfill(); // rejouée : pas de doublon
        $this->assertSame(2, SubscriptionEnrollment::count());
    }
}
