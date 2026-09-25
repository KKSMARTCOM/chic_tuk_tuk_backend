<?php

namespace App\Domains\Booking\Application\Data;

use App\Domains\Booking\Application\Data\Concerns\MapsBookingSchedule;
use App\Models\Booking;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * Une ligne d'historique — GET /driver/bookings/history.
 *
 * ⚠️ `commission` et `driverEarning` ne sont JAMAIS null : les colonnes sont
 * decimal(10,2) NOT NULL DEFAULT 0. Une course annulée avant `complete()` porte donc un
 * gain de 0, et c'est ce qu'il faut afficher. Le `?? $total_price` de la vue Blade est
 * une branche morte : la reproduire donnerait au gain d'une course annulée la valeur de
 * son prix, ce qui est précisément ce qu'il fallait éviter.
 */
final class BookingHistoryData extends BaseData
{
    use MapsBookingSchedule;

    public function __construct(
        public string $id,
        public string $bookingNumber,
        /** `completed` | `cancelled`. */
        #[LiteralTypeScriptType("'completed' | 'cancelled'")] // le filtre de ListBookingHistory
        public string $status,
        public string $fromLocation,
        public string $toLocation,
        public string $pickupAt,
        public int $passengers,
        public ?int $remainingDays,
        public ?string $startedAt,
        public ?string $completedAt,
        public ?string $cancelledAt,
        public ?string $cancellationReason,
        /**
         * En SECONDES, et null tant que la course n'a pas été démarrée ET terminée.
         *
         * La vue Blade affiche `$booking->duration`, dont l'accesseur rend
         * `gmdate('H:i:s', $seconds)` — soit « 00:42:07 ». Exposer des minutes
         * perdrait les secondes et empêcherait le front de reproduire ce format.
         */
        public ?int $durationSeconds,
        public float $totalPrice,
        public float $commission,
        public float $driverEarning,
    ) {}

    public static function fromModel(Booking $booking): self
    {
        $duree = ($booking->started_at && $booking->completed_at)
            ? (int) $booking->started_at->diffInSeconds($booking->completed_at)
            : null;

        return new self(
            id: $booking->id,
            bookingNumber: $booking->booking_number,
            status: $booking->status,
            fromLocation: $booking->from_location,
            toLocation: $booking->to_location,
            pickupAt: self::pickupAt($booking),
            passengers: (int) $booking->passengers,
            remainingDays: $booking->remaining_days,
            startedAt: self::instant($booking->started_at),
            completedAt: self::instant($booking->completed_at),
            cancelledAt: self::instant($booking->cancelled_at),
            cancellationReason: $booking->cancellation_reason,
            durationSeconds: $duree,
            totalPrice: (float) $booking->total_price,
            commission: (float) $booking->commission,
            driverEarning: (float) $booking->driver_earning,
        );
    }
}
