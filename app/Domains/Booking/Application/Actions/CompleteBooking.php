<?php

namespace App\Domains\Booking\Application\Actions;

use App\Domains\Notification\Application\Notifier;
use App\Models\Booking;
use App\Models\Driver;
use App\Services\CommissionService;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Terminer une course — ex-BookingService::complete(), déplacée sans réécriture.
 *
 * CommissionService est injecté plutôt que recopié : la création de la ligne comptable
 * n'appartient pas à ce domaine, et la dupliquer ferait exactement la divergence
 * silencieuse que ce déplacement cherche à supprimer.
 */
final class CompleteBooking
{
    public function __construct(
        private readonly CommissionService $commissionService,
        private readonly Notifier $notifier,
    ) {}

    public function __invoke(string $bookingId, string $driverId): Booking
    {
        $resultat = DB::transaction(function () use ($bookingId, $driverId) {

            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            if (
                $booking->driver_id !== $driverId ||
                $booking->status !== 'in_progress'
            ) {
                throw new ApiException(409, 'BOOKING_NOT_COMPLETABLE', 'Finalisation non autorisée.');
            }

            // Calcul à la completion
            $commission    = (int) ceil((($booking->base_price * 15) / 100) / 50) * 50;
            $driverEarning = $booking->base_price - $commission;

            $booking->update([
                'status'        => 'completed',
                'completed_at'  => now(),
                'commission'     => $commission,
                'driver_earning' => $driverEarning,
            ]);

            Driver::where('id', $driverId)->lockForUpdate()->increment('total_trips');

            $commissionData = [
                'driver_id'       => $driverId,
                'booking_id'      => $bookingId,
                'amount'          => $commission,
                'status'          => 'active',
                'date'            => now(),
            ];

            $this->commissionService->create($commissionData);

            return $booking;
        });

        /**
         * ⚠️ APRÈS la transaction, jamais dedans : un push envoyé à l'intérieur
         * partirait pour une opération qu'un `rollback` annulerait ensuite. `Notifier`
         * n'échoue jamais bruyamment — une notification ne doit pas casser l'action.
         */
        $agent = Driver::with('user')->find($driverId)?->user;

        if ($agent) {
            $this->notifier->bookingCompleted($resultat, $agent);
        }

        return $resultat;
    }
}
