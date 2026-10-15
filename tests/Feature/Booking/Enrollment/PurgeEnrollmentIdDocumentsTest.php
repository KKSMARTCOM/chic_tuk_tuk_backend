<?php

namespace Tests\Feature\Booking\Enrollment;

use App\Models\Booking;
use App\Models\SubscriptionEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Spec 2026-10-14, §6 : 3 mois après la fin de l'abonnement, ou après sa résiliation (2026-10-15). */
class PurgeEnrollmentIdDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private function withDocument(array $parent): SubscriptionEnrollment
    {
        $booking = Booking::factory()->subscriptionParent()->create($parent);
        $path = "enrollments/{$booking->id}/cni.pdf";
        Storage::disk('local')->put($path, '%PDF-1.4');

        return SubscriptionEnrollment::factory()->submittedSchool()->for($booking, 'booking')
            ->create(['id_document_path' => $path, 'id_document_type' => 'cni', 'id_document_uploaded_at' => now()->subYear()]);
    }

    public function test_the_document_goes_three_months_after_the_end_and_not_before(): void
    {
        Storage::fake('local');
        $ended = ['status' => 'completed', 'remaining_days' => 0, 'makeup_go_count' => 0, 'makeup_return_count' => 0];
        $old = $this->withDocument([...$ended, 'subscription_end_date' => now()->subMonths(3)->subDay()->toDateString(), 'pickup_date' => now()->subMonths(4)->toDateString()]);
        $recent = $this->withDocument([...$ended, 'subscription_end_date' => now()->subMonths(3)->addDay()->toDateString(), 'pickup_date' => now()->subMonths(4)->toDateString()]);
        $running = $this->withDocument(['status' => 'confirmed', 'subscription_end_date' => now()->subMonths(6)->toDateString(), 'pickup_date' => now()->subMonths(7)->toDateString()]);

        $this->artisan('app:purge-enrollment-id-documents')->assertSuccessful();

        $old->refresh();
        $this->assertNull($old->id_document_path);
        $this->assertNotNull($old->id_document_purged_at);
        Storage::disk('local')->assertMissing("enrollments/{$old->booking_id}/cni.pdf");
        $this->assertNotNull($recent->fresh()->id_document_path);
        $this->assertNotNull($running->fresh()->id_document_path, 'un abonnement en cours garde sa pièce');
    }

    public function test_a_cancelled_subscription_counts_from_its_cancellation(): void
    {
        Storage::fake('local');
        $cancelled = $this->withDocument(['status' => 'cancelled', 'cancelled_at' => now()->subMonths(4), 'subscription_end_date' => now()->subMonths(5)->toDateString(), 'pickup_date' => now()->subMonths(6)->toDateString()]);

        $this->artisan('app:purge-enrollment-id-documents')->assertSuccessful();

        $this->assertNotNull($cancelled->fresh()->id_document_purged_at);
    }

    /** 2026-10-15 : résilié tôt, la pièce part 3 mois après la résiliation, pas après la fin prévue. */
    public function test_a_subscription_terminated_early_counts_from_its_termination(): void
    {
        Storage::fake('local');
        $ended = ['status' => 'completed', 'remaining_days' => 0, 'makeup_go_count' => 0, 'makeup_return_count' => 0,
            'pickup_date' => now()->subMonths(5)->toDateString(), 'subscription_end_date' => now()->addMonths(2)->toDateString()];
        $old = $this->withDocument([...$ended, 'subscription_terminated_at' => now()->subMonths(3)->subDay()]);
        $recent = $this->withDocument([...$ended, 'subscription_terminated_at' => now()->subMonths(3)->addDay()]);

        $this->artisan('app:purge-enrollment-id-documents')->assertSuccessful();

        $this->assertNotNull($old->fresh()->id_document_purged_at);
        $this->assertNull($recent->fresh()->id_document_purged_at);
    }

    public function test_a_cancelled_subscription_ignores_its_planned_end(): void
    {
        Storage::fake('local');
        $cancelled = $this->withDocument(['status' => 'cancelled', 'cancelled_at' => now()->subMonths(4), 'subscription_end_date' => now()->addMonth()->toDateString(), 'pickup_date' => now()->subMonths(4)->toDateString()]);

        $this->artisan('app:purge-enrollment-id-documents')->assertSuccessful();

        $this->assertNotNull($cancelled->fresh()->id_document_purged_at);
    }

    /** Relecture finale du 2026-10-14 : la cascade en base laissait le fichier sur le disque, hors de portée de la purge. */
    public function test_deleting_a_cancelled_subscription_erases_its_id_document(): void
    {
        Storage::fake('local');
        $enrollment = $this->withDocument(['status' => 'cancelled', 'cancelled_at' => now()]);
        $path = $enrollment->id_document_path;

        app(\App\Domains\Booking\Application\Actions\DeleteAdminBooking::class)($enrollment->booking);

        $this->assertNull(SubscriptionEnrollment::find($enrollment->id));
        Storage::disk('local')->assertMissing($path);
    }
}
