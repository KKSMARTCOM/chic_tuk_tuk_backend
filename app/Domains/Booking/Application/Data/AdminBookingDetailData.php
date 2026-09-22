<?php

namespace App\Domains\Booking\Application\Data;

use App\Domains\Booking\Application\Data\Concerns\DescribesBookingKind;
use App\Domains\Booking\Application\Data\Concerns\MapsBookingSchedule;
use App\Domains\Booking\Domain\BookingLifecycle;
use App\Models\Booking;
use App\Shared\Data\BaseData;

/**
 * Le dossier complet d'une réservation — ex-Admin\BookingController::show().
 *
 * La vue Blade lit quarante champs du modèle ; ils sont repris ici, groupés par ce qu'ils
 * racontent : le trajet, l'argent, l'abonnement, l'agent.
 *
 * ⚠️ Les deux couples de montants ne disent PAS la même chose. `commission` et
 * `driverEarning` sont les montants RÉELS, écrits à la clôture de la course.
 * `commissionPreview` et `driverEarningPreview` en sont l'ESTIMATION, recalculée à chaque
 * lecture depuis le prix de base. Confondre les deux ferait annoncer un gain ACQUIS là où
 * il n'est qu'attendu.
 *
 * ⚠️ En base, `commission` et `driver_earning` sont NOT NULL et valent 0 par défaut : une
 * course non terminée y porte donc un zéro qui ressemble à un montant réglé. L'API les
 * rend `null` tant que la course n'est pas `completed`, pour que le front n'ait pas à
 * deviner si « 0 FCFA » veut dire « rien ne sera versé » ou « rien n'a encore été
 * calculé ». La décision se prend sur le STATUT et non sur la valeur : un zéro est une
 * valeur légitime.
 */
final class AdminBookingDetailData extends BaseData
{
    use DescribesBookingKind;
    use MapsBookingSchedule;

    public function __construct(
        public string $id,
        public string $bookingNumber,
        public string $status,
        /** `single` | `subscription_parent` | `subscription_child` | `simple_return`. */
        public string $kind,
        public string $kindLabel,

        // ----- Le client -----------------------------------------------------
        public ?string $clientName,
        public ?string $phone,
        /** Le compte client, quand la course vient du site et non de l'administration. */
        public ?string $userName,
        public ?string $userEmail,

        // ----- Le trajet -----------------------------------------------------
        public string $fromLocation,
        public string $toLocation,
        /** Kilomètres, tels que le service de tarification les a calculés. */
        public ?float $distance,
        /** Heure MURALE, sans décalage — voir la règle du projet sur `pickup_at`. */
        public string $pickupAt,
        /** Heure murale elle aussi, `HH:MM`, renseignée sur un aller-retour. */
        public ?string $returnTime,
        public bool $roundTrip,
        public bool $isReturn,
        public int $days,
        public int $passengers,
        public ?string $weekDays,
        public ?string $specialRequests,
        public ?string $touristCircuitName,

        // ----- L'argent ------------------------------------------------------
        public float $basePrice,
        public float $totalPrice,
        public float $discount,
        public ?string $promoCode,
        /** Commission RÉELLE, écrite à la clôture. Null tant que la course n'est pas terminée. */
        public ?float $commission,
        /** Gain RÉEL de l'agent. Null lui aussi tant que la course n'est pas terminée. */
        public ?float $driverEarning,
        /** Estimation recalculée à la lecture, pour une course pas encore terminée. */
        public float $commissionPreview,
        public float $driverEarningPreview,

        // ----- L'agent -------------------------------------------------------
        public ?string $driverId,
        public ?string $driverName,
        public ?string $driverPhone,
        /** L'agent titulaire d'un abonnement, qui n'est pas celui d'une course donnée. */
        public ?string $subscriptionDriverName,

        // ----- L'abonnement --------------------------------------------------
        public bool $isRecurring,
        public ?string $parentBookingId,
        public ?string $parentBookingNumber,
        public int $remainingDays,
        public ?string $subscriptionEndDate,
        /** Les courses filles, pour un abonnement parent. */
        public int $childBookingsCount,
        public bool $isRevoked,
        public ?string $revokedAt,

        // ----- L'historique --------------------------------------------------
        public ?string $startedAt,
        public ?string $completedAt,
        public ?string $cancelledAt,
        public ?string $cancellationReason,
        public string $createdAt,
        public string $updatedAt,

        /**
         * La course peut-elle encore être annulée ?
         *
         * Calculé par le serveur et jamais par le front : c'est `Booking::canBeCancelled()`,
         * une règle métier, et la recopier dans un `v-if` en ferait une seconde version à
         * maintenir.
         */
        public bool $canBeCancelled,

        /**
         * Ce que l'écran a le droit de proposer SUR CETTE course.
         *
         * ⚠️ Ces quatre champs sont la correction d'un défaut de conception du Blade :
         * ses règles d'action vivaient en conditions de gabarit (`$canAssign`,
         * `$canRemoveDriver`, `$canDelete` en tête de `show.blade.php`), donc invisibles
         * de l'API et contournables par un appel direct. Le serveur les APPLIQUE et les
         * ANNONCE : le front affiche ce qu'on lui dit, il ne recalcule rien.
         *
         * `allowedStatuses` est vide sur une course close — c'est ainsi que l'écran sait
         * qu'il n'y a plus rien à proposer, sans connaître la matrice de transitions.
         *
         * @var array<int, string>
         */
        public array $allowedStatuses,
        public bool $canAssignDriver,
        public bool $canRemoveDriver,
        public bool $canDelete,
    ) {}

    public static function fromModel(Booking $booking): self
    {
        // Les montants réels n'existent qu'une fois la course terminée — voir le bloc
        // d'en-tête sur le zéro par défaut des deux colonnes.
        $settled = $booking->status === 'completed';

        return new self(
            id: $booking->id,
            bookingNumber: $booking->booking_number,
            status: $booking->status,
            kind: self::kind($booking),
            kindLabel: self::kindLabel($booking),

            clientName: $booking->client_name ?? $booking->user?->name,
            phone: $booking->phone ?? $booking->user?->phone,
            userName: $booking->user?->name,
            userEmail: $booking->user?->email,

            fromLocation: $booking->from_location,
            toLocation: $booking->to_location,
            distance: $booking->distance !== null ? (float) $booking->distance : null,
            pickupAt: self::pickupAt($booking),
            returnTime: $booking->return_time ? substr((string) $booking->return_time, 0, 5) : null,
            roundTrip: (bool) $booking->round_trip,
            isReturn: $booking->trip_type === 'return',
            days: (int) ($booking->days ?? 1),
            passengers: (int) ($booking->passengers ?? 1),
            weekDays: $booking->week_days,
            specialRequests: $booking->special_requests,
            touristCircuitName: $booking->touristCircuit?->name,

            basePrice: (float) $booking->base_price,
            totalPrice: (float) $booking->total_price,
            discount: (float) ($booking->discount ?? 0),
            promoCode: $booking->promoCode?->code,
            commission: $settled ? (float) $booking->commission : null,
            driverEarning: $settled ? (float) $booking->driver_earning : null,
            commissionPreview: (float) $booking->commission_preview,
            driverEarningPreview: (float) $booking->driver_earning_preview,

            driverId: $booking->driver_id,
            driverName: $booking->driver?->user?->name,
            driverPhone: $booking->driver?->user?->phone,
            subscriptionDriverName: $booking->subscriptionDriver?->user?->name,

            isRecurring: (bool) $booking->is_recurring,
            parentBookingId: $booking->parent_booking_id,
            parentBookingNumber: $booking->parentBooking?->booking_number,
            remainingDays: (int) ($booking->remaining_days ?? 0),
            subscriptionEndDate: $booking->subscription_end_date?->format('Y-m-d'),
            childBookingsCount: $booking->childBookings->count(),
            isRevoked: (bool) $booking->is_revoked,
            revokedAt: self::instant($booking->revoked_at),

            startedAt: self::instant($booking->started_at),
            completedAt: self::instant($booking->completed_at),
            cancelledAt: self::instant($booking->cancelled_at),
            cancellationReason: $booking->cancellation_reason,
            createdAt: $booking->created_at->toIso8601String(),
            updatedAt: $booking->updated_at->toIso8601String(),

            canBeCancelled: (bool) $booking->canBeCancelled(),

            allowedStatuses: BookingLifecycle::allowedStatusesFrom($booking->status),
            canAssignDriver: BookingLifecycle::canAssignDriver($booking),
            canRemoveDriver: BookingLifecycle::canRemoveDriver($booking),
            canDelete: BookingLifecycle::canDelete($booking),
        );
    }
}
