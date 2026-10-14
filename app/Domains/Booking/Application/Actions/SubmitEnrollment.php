<?php

namespace App\Domains\Booking\Application\Actions;

use App\Domains\Audit\Application\ActivityJournal;
use App\Domains\Booking\Application\Data\SubmitEnrollmentData;
use App\Domains\Booking\Domain\EnrollmentAccess;
use App\Domains\Notification\Application\Notifier;
use App\Models\Booking;
use App\Models\SubscriptionEnrollment;
use App\Models\SubscriptionEnrollmentPerson;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Envoyer l'inscription (spec 2026-10-14, §4 étape 4 et §5.1).
 *
 * L'inscription est VERROUILLÉE : deux envois simultanés, le second trouve `submitted_at`
 * et répond 410. Le fichier est rangé avant le commit et effacé si la transaction échoue.
 * La notification et le journal partent APRÈS le commit.
 */
final class SubmitEnrollment
{
    public function __construct(private readonly Notifier $notifier, private readonly ActivityJournal $journal) {}

    public function __invoke(string $token, SubmitEnrollmentData $data, ?UploadedFile $file): SubscriptionEnrollment
    {
        $stored = null;

        try {
            $enrollment = DB::transaction(function () use ($token, $data, $file, &$stored) {
                $enrollment = EnrollmentAccess::find($token, lock: true);
                EnrollmentAccess::assertOpen($enrollment);

                $this->writeAnswers($enrollment, $data);

                if ($file) {
                    $stored = $file->store("enrollments/{$enrollment->id}", 'local');
                    $enrollment->fill(['id_document_path' => $stored, 'id_document_uploaded_at' => now()]);
                }

                $enrollment->fill(['commitments_accepted_at' => now(), 'submitted_at' => now()])->save();

                // Le nom générique du landing cède la place au nom saisi, partout dans l'abonnement.
                if (in_array($enrollment->booking->client_name, [null, '', 'Client'], true)) {
                    Booking::query()
                        ->where('id', $enrollment->booking_id)
                        ->orWhere('parent_booking_id', $enrollment->booking_id)
                        ->update(['client_name' => $data->clientName]);
                }

                return $enrollment;
            });
        } catch (\Throwable $e) {
            if ($stored) {
                Storage::disk('local')->delete($stored);
            }
            throw $e;
        }

        $this->notifier->enrollmentSubmitted($enrollment);
        $this->journal->enrollmentSubmitted($enrollment);

        return $enrollment;
    }

    /** Les réponses et les personnes — partagé avec la correction par l'admin. */
    public function writeAnswers(SubscriptionEnrollment $enrollment, SubmitEnrollmentData $data): void
    {
        $school = $data->kind === 'school';

        $enrollment->fill([
            'client_name' => $data->clientName,
            'whatsapp' => $data->whatsapp,
            'email' => $data->email,
            'home_address' => $data->homeAddress,
            'kind' => $data->kind,
            'school_name' => $school ? $data->schoolName : null,
            'school_contact' => $school ? $data->schoolContact : null,
            'workplace' => $school ? null : $data->workplace,
            'work_hours' => $school ? null : $data->workHours,
            'pickup_landmark' => $data->pickupLandmark,
            'dropoff_landmark' => $data->dropoffLandmark,
            'id_document_type' => $data->idDocumentType,
            'id_document_other' => $data->idDocumentType === 'other' ? $data->idDocumentOther : null,
        ])->save();

        $enrollment->people()->delete();
        foreach (array_values($data->people) as $index => $person) {
            SubscriptionEnrollmentPerson::create([
                'enrollment_id' => $enrollment->id,
                'position' => $index + 1,
                'full_name' => $person->fullName,
                'age' => $school ? $person->age : null,
                'school_class' => $school ? $person->schoolClass : null,
                'health_notes' => $school ? $person->healthNotes : null,
                'profession' => $school ? null : $person->profession,
            ]);
        }
        $enrollment->unsetRelation('people');
    }
}
