<?php

namespace App\Domains\Booking\Application\Data;

use App\Domains\Booking\Application\Data\Concerns\MapsBookingSchedule;
use App\Models\Booking;
use App\Shared\Data\BaseData;
use App\Domains\Booking\Domain\Enums\TripType;
use App\Domains\Booking\Domain\Enums\WeekDays;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/**
 * Une course acceptée — GET /driver/bookings/assigned, et retour des cinq actions.
 *
 * Reprend tous les champs d'AvailableBookingData et y ajoute ce que l'acceptation
 * débloque : le numéro, le statut, les coordonnées du client, le prix et la distance.
 */
final class AssignedBookingData extends BaseData
{
    use MapsBookingSchedule;

    public function __construct(
        public string $id,
        public string $bookingNumber,
        /**
         * `pending` | `confirmed` | `in_progress` | `cancelled`.
         *
         * L'écran des courses acceptées n'en montre que deux, mais cette classe sert
         * aussi de RETOUR aux cinq actions : `cancel` renvoie une course `cancelled`, et
         * `revoke` une course redevenue `pending`. Restreindre l'union à deux valeurs
         * rendrait le contrat faux pour deux endpoints sur neuf.
         */
        #[LiteralTypeScriptType("'confirmed' | 'in_progress'")] // le filtre de ListAssignedBookings
        public string $status,
        #[TypeScriptType(TripType::class)]
        public string $tripType,
        public bool $roundTrip,
        public string $fromLocation,
        public string $toLocation,
        public string $pickupAt,
        public ?string $returnTime,
        public bool $isSimpleReturn,
        public bool $isSubscriptionParent,
        public bool $isSubscriptionChild,
        public bool $isRevoked,
        public string $subscriptionLabel,
        public ?string $subscriptionEndDate,
        #[TypeScriptType('?'.WeekDays::class)]
        public ?string $weekDays,
        public ?int $days,
        public ?int $remainingDays,
        public ?string $parentClientName,
        // Ce que l'acceptation débloque :
        public ?string $clientName,
        public ?string $phone,
        public ?string $specialRequests,
        public float $basePrice,
        public ?float $distance,
        public ?int $subscriptionIndex,
        /** Instant réel, décalage compris — sert au chronomètre du front. */
        public ?string $startedAt,
        public bool $canBeCancelled,
    ) {}

    /** Attend une course ayant chargé `user` et `parentBooking.user`. */
    public static function fromModel(Booking $booking): self
    {
        $parent = $booking->parentBooking;

        return new self(
            id: $booking->id,
            bookingNumber: $booking->booking_number,
            status: $booking->status,
            tripType: $booking->trip_type,
            roundTrip: (bool) $booking->round_trip,
            fromLocation: $booking->from_location,
            toLocation: $booking->to_location,
            pickupAt: self::pickupAt($booking),
            returnTime: $booking->return_time,
            isSimpleReturn: $booking->is_simple_return,
            isSubscriptionParent: $booking->is_subscription_parent,
            isSubscriptionChild: $booking->is_subscription_child,
            isRevoked: (bool) $booking->is_revoked,
            subscriptionLabel: $booking->subscription_label,
            subscriptionEndDate: $booking->subscription_end_date?->format('Y-m-d'),
            weekDays: $booking->week_days,
            days: $booking->days,
            remainingDays: $booking->remaining_days,
            parentClientName: $parent
                ? ($parent->client_name ?? $parent->user?->name ?? $parent->booking_number)
                : null,
            clientName: $booking->client_name ?? $booking->user?->name,
            phone: $booking->phone,
            specialRequests: $booking->special_requests,
            // decimal:2 → chaîne. La conversion est obligatoire, pas cosmétique.
            basePrice: (float) $booking->base_price,
            distance: $booking->distance !== null ? (float) $booking->distance : null,
            // ⚠️ Cet accesseur exécute une requête de comptage par course. Acceptable
            // sur une liste bornée ; à ne pas transposer tel quel à un écran d'admin.
            subscriptionIndex: $booking->subscription_index,
            startedAt: self::instant($booking->started_at),
            canBeCancelled: $booking->canBeCancelled(),
        );
    }
}
