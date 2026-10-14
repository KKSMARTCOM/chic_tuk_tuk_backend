<?php

namespace Tests\Feature\Booking\Enrollment;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Booking;
use App\Models\SubscriptionEnrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Spec 2026-10-14, §5.2. */
class AdminEnrollmentApiTest extends TestCase
{
    use RefreshDatabase;

    private function login(array $permissions): string
    {
        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])->json('token');
    }

    private function asBearer(string $token): self
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_the_booking_shows_a_missing_enrollment_with_its_link(): void
    {
        config(['app.front_landing_url' => 'https://chictuktuk.com']);
        $enrollment = SubscriptionEnrollment::factory()->create();

        $this->asBearer($this->login(['view-bookings']))
            ->getJson("/api/v1/admin/bookings/{$enrollment->booking_id}")
            ->assertOk()
            ->assertJsonPath('enrollment.status', 'missing')
            ->assertJsonPath('enrollment.url', "https://chictuktuk.com/inscription/{$enrollment->token}")
            ->assertJsonPath('enrollment.link_expired', false);
    }

    public function test_a_single_ride_has_no_enrollment_block(): void
    {
        $booking = Booking::factory()->create();

        $this->asBearer($this->login(['view-bookings']))
            ->getJson("/api/v1/admin/bookings/{$booking->id}")->assertOk()->assertJsonPath('enrollment', null);
    }

    public function test_a_submitted_enrollment_shows_its_answers_and_people(): void
    {
        $enrollment = SubscriptionEnrollment::factory()->submittedSchool(2)->create();

        $this->asBearer($this->login(['view-bookings']))
            ->getJson("/api/v1/admin/bookings/{$enrollment->booking_id}")
            ->assertJsonPath('enrollment.status', 'submitted')
            ->assertJsonPath('enrollment.school_name', 'Collège Père Aupiais')
            ->assertJsonPath('enrollment.people.1.full_name', 'Enfant 2')
            ->assertJsonPath('enrollment.has_id_document', false);
    }

    public function test_the_admin_corrects_a_submitted_enrollment_and_it_is_journaled(): void
    {
        $enrollment = SubscriptionEnrollment::factory()->submittedSchool()->create();
        $payload = [
            'client_name' => 'Awa Dossou', 'whatsapp' => '+22997000000', 'email' => null, 'home_address' => 'Cadjehoun',
            'kind' => 'school', 'school_name' => 'CEG Gbégamey', 'school_contact' => null, 'workplace' => null, 'work_hours' => null,
            'pickup_landmark' => null, 'dropoff_landmark' => null, 'id_document_type' => null, 'id_document_other' => null,
            'people' => [['full_name' => 'Enfant 1', 'age' => 10, 'school_class' => 'CM2', 'health_notes' => 'RAS']],
        ];

        $this->asBearer($this->login(['view-bookings', 'edit-bookings']))
            ->putJson("/api/v1/admin/bookings/{$enrollment->booking_id}/enrollment", $payload)
            ->assertOk()
            ->assertJsonPath('enrollment.school_name', 'CEG Gbégamey')
            ->assertJsonPath('enrollment.people.0.school_class', 'CM2');

        $this->assertNotNull($enrollment->fresh()->corrected_at);
        $this->assertSame(1, Activity::where('event', 'booking.enrollment_corrected')->count());
    }

    public function test_a_missing_enrollment_is_not_corrected_and_rights_are_checked(): void
    {
        $missing = SubscriptionEnrollment::factory()->create();
        $sent = SubscriptionEnrollment::factory()->submittedSchool()->create();

        $this->asBearer($this->login(['view-bookings', 'edit-bookings']))
            ->putJson("/api/v1/admin/bookings/{$missing->booking_id}/enrollment", ['client_name' => 'X'])
            ->assertStatus(409)->assertJsonPath('code', 'ENROLLMENT_NOT_SUBMITTED');

        $this->asBearer($this->login(['view-bookings']))
            ->putJson("/api/v1/admin/bookings/{$sent->booking_id}/enrollment", [])->assertForbidden();
        $this->asBearer($this->login(['view-bookings']))
            ->postJson("/api/v1/admin/bookings/{$missing->booking_id}/enrollment/link")->assertForbidden();
    }

    public function test_an_expired_link_is_renewed_for_30_days(): void
    {
        $enrollment = SubscriptionEnrollment::factory()->expired()->create();
        $token = $enrollment->token;

        $this->asBearer($this->login(['view-bookings', 'edit-bookings']))
            ->postJson("/api/v1/admin/bookings/{$enrollment->booking_id}/enrollment/link")
            ->assertOk()->assertJsonPath('enrollment.link_expired', false);

        $enrollment->refresh();
        $this->assertSame($token, $enrollment->token);
        $this->assertTrue($enrollment->token_expires_at->greaterThan(now()->addDays(29)));
        $this->assertSame(1, Activity::where('event', 'booking.enrollment_link_renewed')->count());
    }

    public function test_the_id_document_is_downloaded_or_said_purged(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('enrollments/x/cni.pdf', '%PDF-1.4');
        $with = SubscriptionEnrollment::factory()->submittedSchool()->create(['id_document_path' => 'enrollments/x/cni.pdf', 'id_document_type' => 'cni', 'id_document_uploaded_at' => now()]);
        $purged = SubscriptionEnrollment::factory()->submittedSchool()->create(['id_document_purged_at' => now(), 'id_document_type' => 'cni']);
        $none = SubscriptionEnrollment::factory()->submittedSchool()->create();
        $token = $this->login(['view-bookings']);

        $this->asBearer($token)->get("/api/v1/admin/bookings/{$with->booking_id}/enrollment/id-document")->assertOk();
        $this->asBearer($token)->getJson("/api/v1/admin/bookings/{$purged->booking_id}/enrollment/id-document")
            ->assertStatus(410)->assertJsonPath('code', 'ENROLLMENT_ID_DOCUMENT_PURGED');
        $this->asBearer($token)->getJson("/api/v1/admin/bookings/{$none->booking_id}/enrollment/id-document")->assertNotFound();
    }

    public function test_the_list_filters_on_the_enrollment(): void
    {
        $missing = SubscriptionEnrollment::factory()->create();
        $sent = SubscriptionEnrollment::factory()->submittedSchool()->create();
        $token = $this->login(['view-bookings']);

        $ids = fn (string $value) => collect($this->asBearer($token)->getJson("/api/v1/admin/bookings?filter[enrollment]={$value}")->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$sent->booking_id], $ids('submitted'));
        $this->assertSame([$missing->booking_id], $ids('missing'));
        $this->asBearer($token)->getJson('/api/v1/admin/bookings?filter[enrollment]=autre')->assertStatus(400);
    }
}
