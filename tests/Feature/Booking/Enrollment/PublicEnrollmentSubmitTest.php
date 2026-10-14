<?php

namespace Tests\Feature\Booking\Enrollment;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Booking;
use App\Models\Notification;
use App\Models\SubscriptionEnrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** Spec 2026-10-14, §4 (étape 4) et §5.1 (POST). */
class PublicEnrollmentSubmitTest extends TestCase
{
    use RefreshDatabase;

    private const COMMITMENTS = ['payment', 'notify_changes', 'cooperate', 'planned_service', 'absences_impact', 'monthly_due'];

    private function school(array $extra = []): array
    {
        return array_merge([
            'client_name' => 'Awa Dossou', 'whatsapp' => '+22997000000', 'email' => null,
            'home_address' => 'Cadjehoun, rue 12', 'kind' => 'school',
            'school_name' => 'Collège Père Aupiais', 'school_contact' => null,
            'workplace' => null, 'work_hours' => null,
            'pickup_landmark' => 'Pharmacie Camp Guézo', 'dropoff_landmark' => 'Entrée principale',
            'people' => [
                ['full_name' => 'Kemi Dossou', 'age' => 9, 'school_class' => 'CM1', 'health_notes' => 'Allergie aux arachides', 'profession' => null],
            ],
            'commitments' => array_fill_keys(self::COMMITMENTS, true),
            'id_document_type' => null, 'id_document_other' => null,
        ], $extra);
    }

    private function enrollment(): SubscriptionEnrollment
    {
        return SubscriptionEnrollment::factory()->for(Booking::factory()->subscriptionParent()->state(['client_name' => 'Client']), 'booking')->create();
    }

    public function test_a_school_enrollment_is_saved_with_its_children_and_closes_the_link(): void
    {
        $admin = User::factory()->profil(Profil::Admin)->create();
        $enrollment = $this->enrollment();
        $payload = $this->school(['people' => [
            ['full_name' => 'Kemi Dossou', 'age' => 9, 'school_class' => 'CM1', 'health_notes' => 'RAS'],
            ['full_name' => 'Tobi Dossou', 'age' => 6, 'school_class' => 'CP', 'health_notes' => 'Asthme'],
        ]]);

        $this->post("/api/v1/public/enrollments/{$enrollment->token}", $payload, ['Accept' => 'application/json'])->assertCreated();

        $enrollment->refresh();
        $this->assertNotNull($enrollment->submitted_at);
        $this->assertNotNull($enrollment->commitments_accepted_at);
        $this->assertSame(['Kemi Dossou', 'Tobi Dossou'], $enrollment->people->pluck('full_name')->all());
        $this->assertSame('Awa Dossou', $enrollment->booking->client_name, 'le nom générique « Client » est remplacé');
        $this->assertSame('Inscription reçue', Notification::where('user_id', $admin->id)->sole()->title);
        $this->assertSame(1, Activity::where('event', 'booking.enrollment_submitted')->count());

        $this->getJson("/api/v1/public/enrollments/{$enrollment->token}")->assertStatus(410);
    }

    public function test_a_name_typed_by_the_admin_is_kept(): void
    {
        $enrollment = SubscriptionEnrollment::factory()->for(Booking::factory()->subscriptionParent()->state(['client_name' => 'Mme Houngbo']), 'booking')->create();

        $this->post("/api/v1/public/enrollments/{$enrollment->token}", $this->school(), ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame('Mme Houngbo', $enrollment->booking->fresh()->client_name);
    }

    public function test_a_work_enrollment_needs_a_name_per_passenger_only(): void
    {
        $enrollment = $this->enrollment();
        $payload = $this->school(['kind' => 'work', 'school_name' => null, 'workplace' => 'Ganhi', 'work_hours' => '8h00 - 17h00',
            'people' => [['full_name' => 'Koffi Agbo', 'profession' => 'Comptable'], ['full_name' => 'Sena Agbo']]]);

        $this->post("/api/v1/public/enrollments/{$enrollment->token}", $payload, ['Accept' => 'application/json'])->assertCreated();
        $this->assertNull($enrollment->fresh()->people->first()->age);
    }

    public function test_the_answers_are_checked(): void
    {
        $enrollment = $this->enrollment();
        $cases = [
            'school_name' => $this->school(['school_name' => null]),
            'people.0.age' => $this->school(['people' => [['full_name' => 'Kemi', 'school_class' => 'CM1', 'health_notes' => 'RAS']]]),
            'people.0.health_notes' => $this->school(['people' => [['full_name' => 'Kemi', 'age' => 9, 'school_class' => 'CM1']]]),
            'people' => $this->school(['people' => []]),
            'commitments.cooperate' => $this->school(['commitments' => array_merge(array_fill_keys(self::COMMITMENTS, true), ['cooperate' => false])]),
            'id_document_other' => $this->school(['id_document_type' => 'other']),
        ];
        $cases['people.0.full_name'] = $this->school(['kind' => 'work', 'school_name' => null, 'people' => [['profession' => 'Comptable']]]);
        $cases['people '] = $this->school(['people' => array_fill(0, 6, ['full_name' => 'X', 'age' => 9, 'school_class' => 'CM1', 'health_notes' => 'RAS'])]);

        foreach ($cases as $field => $payload) {
            $this->post("/api/v1/public/enrollments/{$enrollment->token}", $payload, ['Accept' => 'application/json'])
                ->assertStatus(422)->assertJsonValidationErrors([trim($field)]);
        }
        $this->assertNull($enrollment->fresh()->submitted_at);
    }

    public function test_the_id_document_is_checked_on_its_content_and_stored_privately(): void
    {
        Storage::fake('local');
        $enrollment = $this->enrollment();

        // ⚠️ Un VRAI fichier : un faux de `UploadedFile::fake()` tire son type de l'extension.
        $exe = tempnam(sys_get_temp_dir(), 'enr');
        file_put_contents($exe, "<?php echo 1;\n");
        $fake = new UploadedFile($exe, 'cni.pdf', null, null, true); // un script renommé
        $this->post("/api/v1/public/enrollments/{$enrollment->token}", $this->school(['id_document_type' => 'cni', 'id_document' => $fake]), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['id_document']);

        $tooBig = UploadedFile::fake()->create('cni.pdf', 10_241, 'application/pdf');
        $this->post("/api/v1/public/enrollments/{$enrollment->token}", $this->school(['id_document_type' => 'cni', 'id_document' => $tooBig]), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['id_document']);

        $pdf = UploadedFile::fake()->create('cni.pdf', 100, 'application/pdf');
        $this->post("/api/v1/public/enrollments/{$enrollment->token}", $this->school(['id_document_type' => 'cni', 'id_document' => $pdf]), ['Accept' => 'application/json'])
            ->assertCreated();

        $enrollment->refresh();
        $this->assertStringStartsWith("enrollments/{$enrollment->id}/", $enrollment->id_document_path);
        Storage::disk('local')->assertExists($enrollment->id_document_path);
        $this->assertSame('cni', $enrollment->id_document_type);
    }

    public function test_a_second_submission_is_refused_and_writes_nothing(): void
    {
        $enrollment = $this->enrollment();

        $this->post("/api/v1/public/enrollments/{$enrollment->token}", $this->school(), ['Accept' => 'application/json'])->assertCreated();
        $this->post("/api/v1/public/enrollments/{$enrollment->token}", $this->school(['client_name' => 'Autre']), ['Accept' => 'application/json'])
            ->assertStatus(410)->assertJsonPath('code', 'ENROLLMENT_ALREADY_SUBMITTED');

        $this->assertSame('Awa Dossou', $enrollment->fresh()->client_name);
        $this->assertSame(1, $enrollment->fresh()->people()->count());
    }
}
