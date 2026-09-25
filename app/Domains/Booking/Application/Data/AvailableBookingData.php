<?php

namespace App\Domains\Booking\Application\Data;

use App\Domains\Booking\Application\Data\Concerns\MapsBookingSchedule;
use App\Models\Booking;
use App\Shared\Data\BaseData;
use App\Domains\Booking\Domain\Enums\TripType;
use App\Domains\Booking\Domain\Enums\WeekDays;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/**
 * Une course que l'agent peut prendre — GET /driver/bookings/available.
 *
 * ⚠️ AUCUNE coordonnée client. Vérifié dans resources/views/pages/driver/bookings/
 * available.blade.php : l'écran n'affiche ni téléphone, ni nom du client, ni demandes
 * particulières, ni prix. Seul le nom du client d'un abonnement PARENT y figure, et
 * c'est `parent_client_name`.
 *
 * C'est une règle de confidentialité, pas une mise en page : elle est portée par la
 * structure de cette classe, et non par des champs facultatifs qu'un oubli remplirait.
 * Ne JAMAIS ajouter `phone`, `client_name`, `special_requests` ni `base_price` ici.
 */
final class AvailableBookingData extends BaseData
{
    use MapsBookingSchedule;

    public function __construct(
        public string $id,
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
        /** Jamais null : l'accesseur renvoie « Course unique » par défaut. */
        public string $subscriptionLabel,
        public ?string $subscriptionEndDate,
        /** `lun_ven` | `lun_sam` | `lun_dim` — une chaîne, pas une liste. */
        #[TypeScriptType('?'.WeekDays::class)]
        public ?string $weekDays,
        public ?int $days,
        public ?int $remainingDays,
        /** Le seul nom de client visible avant acceptation. */
        public ?string $parentClientName,
        /**
         * Le numéro de la course parente — « Abonnement CTT-XXXXXXXX » sur un enfant,
         * « Course aller : CTT-XXXXXXXX » sur un retour simple. La vue Blade l'affiche
         * dans les deux cas ; sans lui, l'agent ne sait pas à quoi la course se rattache.
         */
        public ?string $parentBookingNumber,
        /** L'horaire de la course aller, affiché sur un retour simple. */
        public ?string $parentPickupAt,
        /**
         * Cet agent peut-il révoquer cette course ?
         *
         * ⚠️ Transposé du Blade au caractère près :
         * `@if ($isChild && $booking->subscription_driver_id === auth()->user()->driver?->id)`.
         * On ne révoque QUE les enfants d'abonnement dont on est le titulaire — jamais un
         * abonnement parent, qui s'accepte comme une course ordinaire, ni une course
         * retour. Le titulaire voit ensuite les enfants générés par le cron, et ce sont
         * eux qu'il peut rendre.
         */
        public bool $canBeRevoked,
    ) {}

    /**
     * Attend une course ayant chargé `parentBooking.user`.
     *
     * `$driverId` est celui de l'agent qui REGARDE : `canBeRevoked` en dépend, et le
     * calculer sans lui donnerait le même bouton à tout le monde.
     */
    public static function fromModel(Booking $booking, ?string $driverId = null): self
    {
        $parent = $booking->parentBooking;

        return new self(
            id: $booking->id,
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
            parentClientName: self::nomDuClientParent($booking),
            parentBookingNumber: $parent?->booking_number,
            parentPickupAt: $parent ? self::pickupAt($parent) : null,
            canBeRevoked: $booking->is_subscription_child
                && $driverId !== null
                && $booking->subscription_driver_id === $driverId,
        );
    }

    /**
     * La cascade exacte de la vue Blade : client_name, puis le nom de l'utilisateur,
     * puis le numéro de la course du parent. Null s'il n'y a pas de parent.
     */
    private static function nomDuClientParent(Booking $booking): ?string
    {
        $parent = $booking->parentBooking;

        if (! $parent) {
            return null;
        }

        return $parent->client_name ?? $parent->user?->name ?? $parent->booking_number;
    }
}
