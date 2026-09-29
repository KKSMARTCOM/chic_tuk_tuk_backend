<?php

namespace App\Domains\Booking\Presentation\Api\V1\Driver;

use App\Domains\Audit\Application\ActivityJournal;
use App\Domains\Booking\Application\Actions\AcceptBooking;
use App\Domains\Booking\Application\Actions\CancelBooking;
use App\Domains\Booking\Application\Actions\CompleteBooking;
use App\Domains\Booking\Application\Actions\ListAssignedBookings;
use App\Domains\Booking\Application\Actions\ListAvailableBookings;
use App\Domains\Booking\Application\Actions\ListBookingHistory;
use App\Domains\Booking\Application\Actions\RevokeFromSubscription;
use App\Domains\Booking\Application\Actions\StartBooking;
use App\Domains\Booking\Application\Data\AssignedBookingData;
use App\Domains\Booking\Application\Data\AvailableBookingData;
use App\Domains\Booking\Application\Data\BookingHistoryPageData;
use App\Domains\Booking\Application\Data\CancelBookingData;
use App\Models\Booking;
use App\Models\Driver;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Les courses de l'espace agent.
 *
 * Mêmes règles de try/catch que le VehicleController de l'espace propriétaire :
 * ValidationException, ApiException et ModelNotFoundException relancées EN PREMIER —
 * elles sont la réponse voulue et le renderer sait déjà les rendre ; le message
 * d'exception va au JOURNAL et jamais dans la réponse ; et on attrape \Throwable et non
 * \Exception, car les \Error (TypeError, ValueError) n'héritent pas d'Exception.
 *
 * ⚠️ ModelNotFoundException DOIT figurer dans le premier catch : attrapée par le
 * \Throwable plus bas, une course introuvable deviendrait un 500 et cesserait d'être
 * introuvable pour paraître en panne.
 */
final class BookingController
{
    /** Chaque geste de l'agent est tracé APRÈS sa réussite : voir `ActivityJournal`. */
    public function __construct(private readonly ActivityJournal $journal) {}

    public function available(Request $request, ListAvailableBookings $list): JsonResponse
    {
        return $this->lire($request, 'available', fn (Driver $driver) => $list($driver->id)
            // L'agent est passé au Data : `can_be_revoked` dépend de QUI regarde.
            ->map(fn (Booking $b) => AvailableBookingData::fromModel($b, $driver->id)));
    }

    public function assigned(Request $request, ListAssignedBookings $list): JsonResponse
    {
        return $this->lire($request, 'assigned', fn (Driver $driver) => $list($driver->id)
            ->map(fn (Booking $b) => AssignedBookingData::fromModel($b)));
    }

    public function history(Request $request, ListBookingHistory $list): JsonResponse
    {
        return $this->lire($request, 'history', fn (Driver $driver) => BookingHistoryPageData::fromPaginator(
            $list($driver->id, $request->query('search')),
        ));
    }

    public function accept(Request $request, string $id, AcceptBooking $accept): JsonResponse
    {
        return $this->ecrire($request, 'accept', function (Driver $driver) use ($id, $accept) {
            // AcceptBooking ne renvoie rien — comme take() avant elle. La course est
            // relue APRÈS l'action : la relire avant donnerait l'état d'avant.
            $accept($id, $driver->id);
            $this->journal->bookingAccepted(Booking::findOrFail($id));

            return $this->courseAssignee($id);
        });
    }

    public function start(Request $request, string $id, StartBooking $start): JsonResponse
    {
        return $this->ecrire($request, 'start', function (Driver $driver) use ($id, $start) {
            $start($id, $driver->id);
            $this->journal->bookingStarted(Booking::findOrFail($id));

            return $this->courseAssignee($id);
        });
    }

    public function complete(Request $request, string $id, CompleteBooking $complete): JsonResponse
    {
        return $this->ecrire($request, 'complete', function (Driver $driver) use ($id, $complete) {
            $complete($id, $driver->id);
            $this->journal->bookingCompleted(Booking::findOrFail($id));

            return $this->courseAssignee($id);
        });
    }

    /**
     * ⚠️ `CancelBookingData` est TYPE-HINTÉE dans la signature, comme le fait déjà
     * Public\BookingController::store : spatie/laravel-data la construit et la valide
     * depuis la requête avant même d'entrer dans la méthode. Une validation ratée lève
     * donc une ValidationException AVANT le try/catch, et le renderer la traduit en
     * 422 VALIDATION_FAILED — ce qui est le comportement voulu.
     *
     * Le motif est OBLIGATOIRE ici, contrairement au chemin Blade qui lui substitue
     * « Annulée par le Agent » quand le champ est vide.
     */
    public function cancel(Request $request, string $id, CancelBookingData $data, CancelBooking $cancel): JsonResponse
    {
        return $this->ecrire($request, 'cancel', function (Driver $driver) use ($data, $id, $cancel) {
            $booking = $cancel($id, $driver->id, $data->cancellationReason);
            $this->journal->bookingCancelled($booking, $data->cancellationReason);

            return AssignedBookingData::fromModel(
                $booking->load(['user', 'parentBooking.user']),
            );
        });
    }

    public function revoke(Request $request, string $id, RevokeFromSubscription $revoke): JsonResponse
    {
        return $this->ecrire($request, 'revoke', function (Driver $driver) use ($id, $revoke) {
            $revoke($id, $driver->id);
            $this->journal->subscriptionRevoked(Booking::findOrFail($id));

            return $this->courseAssignee($id);
        });
    }

    // ----- Plomberie commune ---------------------------------------------------

    /**
     * L'agent courant.
     *
     * Un utilisateur de profil `driver` sans ligne `drivers` est une incohérence de
     * données, pas une panne : sans ce garde, `$driver->id` lèverait un Error que le
     * renderer transformerait en 500 illisible.
     */
    private function agent(Request $request): Driver
    {
        $driver = $request->user()?->driver;

        if (! $driver) {
            throw new ApiException(
                409,
                'DRIVER_PROFILE_MISSING',
                'Votre compte agent est incomplet. Contactez un administrateur.',
            );
        }

        return $driver;
    }

    /** Relit une course et la rend sous sa forme « acceptée », relations chargées. */
    private function courseAssignee(string $id): AssignedBookingData
    {
        return AssignedBookingData::fromModel(
            Booking::with(['user', 'parentBooking.user'])->findOrFail($id),
        );
    }

    private function lire(Request $request, string $quoi, callable $travail): JsonResponse
    {
        return $this->repondre($request, $quoi, $travail, 'Vos courses n\'ont pas pu être chargées. Réessayez.');
    }

    private function ecrire(Request $request, string $quoi, callable $travail): JsonResponse
    {
        return $this->repondre($request, $quoi, $travail, 'L\'action n\'a pas pu être effectuée. Réessayez.');
    }

    private function repondre(Request $request, string $quoi, callable $travail, string $messageDeSecours): JsonResponse
    {
        try {
            return response()->json($travail($this->agent($request)));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error("Erreur sur l'espace agent [{$quoi}] : ".$e->getMessage(), [
                'exception' => $e,
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => $messageDeSecours,
                'code' => 'DRIVER_BOOKINGS_FAILED',
            ], 500);
        }
    }
}
