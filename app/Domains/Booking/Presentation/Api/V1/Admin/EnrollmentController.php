<?php

namespace App\Domains\Booking\Presentation\Api\V1\Admin;

use App\Domains\Audit\Application\ActivityJournal;
use App\Domains\Booking\Application\Actions\CorrectEnrollment;
use App\Domains\Booking\Application\Actions\RenewEnrollmentLink;
use App\Domains\Booking\Application\Data\AdminBookingDetailData;
use App\Domains\Booking\Application\Data\SubmitEnrollmentData;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\SubscriptionEnrollment;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** L'inscription d'un abonnement, vue de l'administration (spec 2026-10-14, §5.2). */
final class EnrollmentController extends Controller
{
    public function __construct(private readonly ActivityJournal $journal) {}

    public function update(Request $request, string $bookingId, CorrectEnrollment $correct): JsonResponse
    {
        try {
            $enrollment = $this->enrollmentOf($bookingId);
            // L'état avant les champs : corriger une inscription jamais remplie n'a pas de sens.
            if ($enrollment->submitted_at === null) {
                throw new ApiException(409, 'ENROLLMENT_NOT_SUBMITTED', 'Cette inscription n\'a pas encore été remplie par le client.');
            }
            $validated = Validator::make($request->all(), SubmitEnrollmentData::answerRules(), SubmitEnrollmentData::messages())->validate();
            $fields = $correct($enrollment, SubmitEnrollmentData::from($validated + ['commitments' => []]), $request->user());
            $this->journal->enrollmentCorrected($enrollment->fresh('booking'), $fields);

            return $this->detail($bookingId);
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failed($e, 'la correction d\'une inscription', 'L\'inscription n\'a pas pu être corrigée.', 'ENROLLMENT_CORRECT_FAILED');
        }
    }

    public function renewLink(string $bookingId, RenewEnrollmentLink $renew): JsonResponse
    {
        try {
            $enrollment = $renew($this->enrollmentOf($bookingId));
            $this->journal->enrollmentLinkRenewed($enrollment->load('booking'));

            return $this->detail($bookingId);
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failed($e, 'le renouvellement d\'un lien d\'inscription', 'Le lien n\'a pas pu être renouvelé.', 'ENROLLMENT_LINK_FAILED');
        }
    }

    public function idDocument(string $bookingId): Response
    {
        try {
            $enrollment = $this->enrollmentOf($bookingId);
            if ($enrollment->id_document_purged_at) {
                throw new ApiException(410, 'ENROLLMENT_ID_DOCUMENT_PURGED', 'La pièce d\'identité a été effacée le '.$enrollment->id_document_purged_at->format('d/m/Y').'.');
            }
            if (! $enrollment->id_document_path || ! Storage::disk('local')->exists($enrollment->id_document_path)) {
                throw (new ModelNotFoundException)->setModel(SubscriptionEnrollment::class);
            }

            return Storage::disk('local')->download($enrollment->id_document_path, 'piece-identite-'.$enrollment->booking->booking_number.'.'.pathinfo($enrollment->id_document_path, PATHINFO_EXTENSION));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failed($e, 'le téléchargement d\'une pièce d\'identité', 'La pièce n\'a pas pu être téléchargée.', 'ENROLLMENT_ID_DOCUMENT_FAILED');
        }
    }

    private function enrollmentOf(string $bookingId): SubscriptionEnrollment
    {
        return SubscriptionEnrollment::query()->with('booking')->where('booking_id', $bookingId)->firstOrFail();
    }

    private function detail(string $bookingId): JsonResponse
    {
        $booking = Booking::with(['user', 'driver.user', 'touristCircuit', 'promoCode', 'parentBooking.user', 'childBookings', 'subscriptionDriver.user', 'enrollment.people'])->findOrFail($bookingId);

        return response()->json(AdminBookingDetailData::fromModel($booking));
    }

    private function failed(\Throwable $e, string $what, string $message, string $code): JsonResponse
    {
        Log::error("Échec de {$what}", ['exception' => $e]);

        return response()->json(['message' => $message, 'code' => $code], 500);
    }
}
