<?php

namespace App\Domains\Booking\Presentation\Api\V1\Public;

use App\Domains\Booking\Application\Actions\SubmitEnrollment;
use App\Domains\Booking\Application\Data\PublicEnrollmentData;
use App\Domains\Booking\Application\Data\SubmitEnrollmentData;
use App\Domains\Booking\Domain\EnrollmentAccess;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** L'inscription d'un abonnement, par son jeton (spec 2026-10-14, §5.1). */
final class EnrollmentController extends Controller
{
    public function show(string $token): JsonResponse
    {
        try {
            $enrollment = EnrollmentAccess::find($token);
            EnrollmentAccess::assertOpen($enrollment);

            return response()->json(PublicEnrollmentData::fromEnrollment($enrollment));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Inscription : lecture impossible', ['exception' => $e]);

            return response()->json(['message' => 'Le formulaire n\'a pas pu être chargé. Réessayez.', 'code' => 'ENROLLMENT_READ_FAILED'], 500);
        }
    }

    public function submit(Request $request, string $token, SubmitEnrollmentData $data, SubmitEnrollment $submit): JsonResponse
    {
        try {
            $submit($token, $data, $request->file('id_document'));

            return response()->json(['message' => 'Merci, votre inscription est enregistrée.'], 201);
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Inscription : envoi impossible', ['exception' => $e]);

            return response()->json(['message' => 'L\'inscription n\'a pas pu être enregistrée. Réessayez.', 'code' => 'ENROLLMENT_SUBMIT_FAILED'], 500);
        }
    }
}
