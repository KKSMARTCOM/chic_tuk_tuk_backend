<?php

namespace App\Domains\Booking\Presentation\Api\V1\Public;

use App\Domains\Audit\Application\ActivityJournal;
use App\Domains\Booking\Application\Actions\CreatePublicBooking;
use App\Domains\Booking\Application\Data\BookingConfirmationData;
use App\Domains\Booking\Application\Data\CreatePublicBookingData;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class BookingController extends Controller
{
    /**
     * POST /api/v1/public/bookings
     *
     * Endpoint anonyme : throttling posé sur la route, aucun prix accepté en entrée.
     */
    public function store(CreatePublicBookingData $data, CreatePublicBooking $action, ActivityJournal $journal)
    {
        try {
            $booking = $action->execute($data);
        } catch (\Throwable $e) {
            Log::error('Échec de création d\'une réservation publique : '.$e->getMessage(), [
                'exception' => $e,
                'phone' => $data->phone,
            ]);

            // `CreateBooking` lève une exception générique quand l'itinéraire ne peut
            // pas être calculé : le détail ne doit pas remonter au client.
            throw new UnprocessableEntityHttpException(
                'La réservation n\'a pas pu être enregistrée. Vérifiez votre trajet et réessayez.',
            );
        }

        $journal->bookingCreatedOnline($booking);

        return BookingConfirmationData::from($booking)
            ->additional(['message' => 'Votre demande de réservation a bien été enregistrée.'])
            ->toResponse(request())
            ->setStatusCode(201);
    }
}
