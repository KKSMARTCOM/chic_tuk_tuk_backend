<?php

namespace App\Domains\Booking\Application\Actions;

use App\Domains\Booking\Application\Data\SubmitEnrollmentData;
use App\Models\SubscriptionEnrollment;
use App\Models\User;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/** Corriger une inscription envoyée (spec 2026-10-14, §5.2). Rend les champs changés, pour le journal. */
final class CorrectEnrollment
{
    public function __construct(private readonly SubmitEnrollment $submit) {}

    /** @return list<string> */
    public function __invoke(SubscriptionEnrollment $enrollment, SubmitEnrollmentData $data, User $by): array
    {
        if ($enrollment->submitted_at === null) {
            throw new ApiException(409, 'ENROLLMENT_NOT_SUBMITTED', 'Cette inscription n\'a pas encore été remplie par le client.');
        }

        return DB::transaction(function () use ($enrollment, $data, $by) {
            $before = $enrollment->only(['client_name', 'whatsapp', 'email', 'home_address', 'kind', 'school_name', 'school_contact', 'workplace', 'work_hours', 'pickup_landmark', 'dropoff_landmark', 'id_document_type', 'id_document_other']);
            $peopleBefore = $enrollment->people()->get(['full_name', 'age', 'school_class', 'health_notes', 'profession'])->toArray();

            $this->submit->writeAnswers($enrollment, $data);
            $enrollment->update(['updated_by' => $by->id, 'corrected_at' => now()]);

            $changed = array_keys(array_diff_assoc(array_map('strval', $enrollment->only(array_keys($before))), array_map('strval', $before)));
            if ($enrollment->people()->get(['full_name', 'age', 'school_class', 'health_notes', 'profession'])->toArray() !== $peopleBefore) {
                $changed[] = 'people';
            }

            return $changed;
        });
    }
}
