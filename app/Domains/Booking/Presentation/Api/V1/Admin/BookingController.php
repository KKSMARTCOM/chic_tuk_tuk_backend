<?php

namespace App\Domains\Booking\Presentation\Api\V1\Admin;

use App\Domains\Booking\Application\Actions\AssignDriverToBooking;
use App\Domains\Booking\Application\Actions\ChangeBookingStatus;
use App\Domains\Booking\Application\Actions\DeleteAdminBooking;
use App\Domains\Booking\Application\Actions\ListAdminBookings;
use App\Domains\Booking\Application\Actions\ListAssignableDrivers;
use App\Domains\Booking\Application\Actions\RemoveDriverFromBooking;
use App\Domains\Booking\Application\Actions\ReopenCompletedBooking;
use App\Domains\Booking\Application\Data\AdminBookingDetailData;
use App\Domains\Booking\Application\Data\AdminBookingPageData;
use App\Domains\Booking\Application\Data\AssignableDriverData;
use App\Domains\Booking\Application\Data\AssignDriverData;
use App\Domains\Booking\Application\Data\ChangeBookingStatusData;
use App\Models\Booking;
use App\Models\Driver;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Les réservations, vues de l'administration : la liste, le dossier de l'une d'elles, et
 * les quatre gestes qui la font avancer.
 *
 * Mêmes règles de try/catch que les autres contrôleurs de l'API : ValidationException,
 * ApiException et ModelNotFoundException relancées EN PREMIER, `\Throwable` et non
 * `\Exception`, et le message d'exception au journal seulement.
 */
final class BookingController
{
    public function index(Request $request, ListAdminBookings $list): JsonResponse
    {
        try {
            $page = $list([
                'status' => $request->query('status'),
                'search' => $request->query('search'),
                'sort' => $request->query('sort'),
                'page' => $request->query('page'),
                'per_page' => $request->query('per_page'),
            ]);

            return response()->json(AdminBookingPageData::fromPaginator($page));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failed($e, $request, 'la liste des réservations',
                'La liste des réservations n\'a pas pu être chargée. Réessayez.', 'ADMIN_BOOKINGS_FAILED');
        }
    }

    public function show(Request $request, string $bookingId): JsonResponse
    {
        try {
            $booking = Booking::with([
                'user', 'driver.user', 'touristCircuit', 'promoCode',
                'parentBooking.user', 'childBookings', 'subscriptionDriver.user',
            ])->findOrFail($bookingId);

            return response()->json(AdminBookingDetailData::fromModel($booking));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failed($e, $request, 'le dossier d\'une réservation',
                'Cette réservation n\'a pas pu être chargée. Réessayez.', 'ADMIN_BOOKING_DETAIL_FAILED');
        }
    }

    /**
     * Les agents à qui la course peut être confiée.
     *
     * ⚠️ Route DISTINCTE de la liste des agents, et non un filtre de celle-ci : le Blade
     * réutilise `/admin/drivers` avec un paramètre `available=1` que le contrôleur ne lit
     * pas, si bien que la fenêtre d'affectation propose tout le monde. Une route qui
     * répond exactement à la question posée ne peut pas se désaccorder ainsi.
     */
    public function assignableDrivers(Request $request, ListAssignableDrivers $list): JsonResponse
    {
        try {
            return response()->json(
                $list()->map(fn (Driver $d) => AssignableDriverData::fromModel($d))->all()
            );
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failed($e, $request, 'la liste des agents affectables',
                'La liste des agents n\'a pas pu être chargée. Réessayez.', 'ASSIGNABLE_DRIVERS_FAILED');
        }
    }

    // ----- Les écritures -----------------------------------------------------

    public function assignDriver(Request $request, string $bookingId, AssignDriverData $data, AssignDriverToBooking $assign): JsonResponse
    {
        try {
            $booking = Booking::findOrFail($bookingId);

            return response()->json(AdminBookingDetailData::fromModel(
                $assign($booking, $data->driverId)->load(['user', 'driver.user', 'parentBooking', 'childBookings'])
            ));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failed($e, $request, 'l\'affectation d\'un agent',
                'Cet agent n\'a pas pu être affecté.', 'BOOKING_ASSIGN_FAILED');
        }
    }

    public function removeDriver(Request $request, string $bookingId, RemoveDriverFromBooking $remove): JsonResponse
    {
        try {
            $booking = Booking::findOrFail($bookingId);

            return response()->json(AdminBookingDetailData::fromModel(
                $remove($booking)->load(['user', 'driver.user', 'parentBooking', 'childBookings'])
            ));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failed($e, $request, 'le retrait d\'un agent',
                'Cet agent n\'a pas pu être retiré.', 'BOOKING_REMOVE_DRIVER_FAILED');
        }
    }

    public function changeStatus(Request $request, string $bookingId, ChangeBookingStatusData $data, ChangeBookingStatus $change): JsonResponse
    {
        try {
            $booking = Booking::findOrFail($bookingId);

            return response()->json(AdminBookingDetailData::fromModel(
                $change($booking, $data->status, $data->cancellationReason)
                    ->load(['user', 'driver.user', 'parentBooking', 'childBookings'])
            ));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failed($e, $request, 'le changement de statut d\'une réservation',
                'Le statut n\'a pas pu être modifié.', 'BOOKING_STATUS_FAILED');
        }
    }

    /**
     * Annuler la clôture d'une course.
     *
     * ⚠️ Route à part, et non un statut de plus dans `changeStatus` : rouvrir DÉFAIT la
     * commission, le gain de l'agent et son compteur de trajets. Glissée dans un menu
     * déroulant, l'opération passerait pour un simple changement d'étiquette.
     */
    public function reopen(Request $request, string $bookingId, ReopenCompletedBooking $reopen): JsonResponse
    {
        try {
            $booking = Booking::findOrFail($bookingId);

            return response()->json(AdminBookingDetailData::fromModel(
                $reopen($booking)->load(['user', 'driver.user', 'parentBooking', 'childBookings'])
            ));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failed($e, $request, 'la réouverture d\'une réservation',
                'Cette réservation n\'a pas pu être rouverte.', 'BOOKING_REOPEN_FAILED');
        }
    }

    public function destroy(Request $request, string $bookingId, DeleteAdminBooking $delete): Response|JsonResponse
    {
        try {
            $delete(Booking::findOrFail($bookingId));

            return response()->noContent();
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failed($e, $request, 'la suppression d\'une réservation',
                'Cette réservation n\'a pas pu être supprimée.', 'BOOKING_DELETE_FAILED');
        }
    }

    private function failed(\Throwable $e, Request $request, string $what, string $message, string $code): JsonResponse
    {
        Log::error("Erreur lors de {$what} : ".$e->getMessage(), [
            'exception' => $e,
            'user_id' => $request->user()?->id,
        ]);

        return response()->json(['message' => $message, 'code' => $code], 500);
    }
}
