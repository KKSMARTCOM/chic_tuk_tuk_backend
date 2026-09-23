<?php

namespace App\Domains\Booking\Application\Data;

use App\Domains\Booking\Application\Data\Concerns\DescribesBookingKind;
use App\Domains\Booking\Application\Data\Concerns\MapsBookingSchedule;
use App\Domains\Booking\Domain\BookingLifecycle;
use App\Models\Booking;
use App\Shared\Data\BaseData;

/**
 * Une ligne du tableau « Réservations » de l'administration — ex-Admin\BookingController::index().
 *
 * ⚠️ La NATURE d'une course tient dans un seul champ, `kind`, là où la vue Blade
 * enchaîne quatre tests d'accesseurs pour choisir son badge. Voir `DescribesBookingKind`,
 * qui porte la règle et explique pourquoi l'ordre de ces tests compte.
 *
 * ⚠️ `roundTrip` et `isReturn` restent SÉPARÉS de `kind` : une course peut être un
 * enfant d'abonnement ET un retour, et le Blade affiche bien deux badges dans ce cas.
 */
final class AdminBookingListItemData extends BaseData
{
    use DescribesBookingKind;
    use MapsBookingSchedule;

    public function __construct(
        public string $id,
        public string $bookingNumber,
        /** `single` | `subscription_parent` | `subscription_child` | `simple_return`. */
        public string $kind,
        /** Le libellé que le Blade affiche dans le badge — « Course 3 — Abonn. CTT-… ». */
        public string $kindLabel,
        public ?string $clientName,
        public ?string $phone,
        public string $fromLocation,
        public string $toLocation,
        /** Heure MURALE, sans décalage — voir la règle du projet sur `pickup_at`. */
        public string $pickupAt,
        public float $basePrice,
        public string $status,
        public ?string $driverId,
        public ?string $driverName,
        public bool $roundTrip,
        /** Cette course EST le retour d'un aller. */
        public bool $isReturn,
        /** Course d'abonnement rendue à tous les agents. */
        public bool $isRevoked,
        /** Nombre de jours de l'abonnement — le Blade l'affiche sur le badge parent. */
        public int $days,
        public string $createdAt,
        /**
         * Les deux actions que la LIGNE propose, comme dans le tableau Blade.
         *
         * ⚠️ Elles sont annoncées par le serveur et non déduites de `driverId` et
         * `status` : les règles sont les mêmes que sur le dossier — une course fille
         * d'abonnement revient à son titulaire, une course close garde son agent — et les
         * recalculer par ligne en ferait une troisième version, après le gabarit Blade et
         * le dossier.
         */
        public bool $canAssignDriver,
        public bool $canRemoveDriver,
        /** Le lien « Modifier » du tableau Blade — uniquement une réservation EN ATTENTE. */
        public bool $canEdit,
    ) {}

    public static function fromModel(Booking $booking): self
    {
        return new self(
            id: $booking->id,
            bookingNumber: $booking->booking_number,
            kind: self::kind($booking),
            kindLabel: self::kindLabel($booking),
            // ⚠️ Trois sources, dans l'ordre du Blade : le nom saisi par l'administrateur,
            // puis le compte client, puis rien. Une course créée depuis l'administration
            // porte `client_name` sans avoir de compte derrière.
            clientName: $booking->client_name ?? $booking->user?->name,
            phone: $booking->phone ?? $booking->user?->phone,
            fromLocation: $booking->from_location,
            toLocation: $booking->to_location,
            pickupAt: self::pickupAt($booking),
            basePrice: (float) $booking->base_price,
            status: $booking->status,
            // L'identifiant de l'AGENT, pas celui de son compte : c'est lui que prend la
            // route d'affectation.
            driverId: $booking->driver_id,
            driverName: $booking->driver?->user?->name,
            roundTrip: (bool) $booking->round_trip,
            isReturn: $booking->trip_type === 'return',
            isRevoked: (bool) $booking->is_revoked,
            days: (int) ($booking->days ?? 1),
            createdAt: $booking->created_at->toIso8601String(),
            canAssignDriver: BookingLifecycle::canAssignDriver($booking),
            canRemoveDriver: BookingLifecycle::canRemoveDriver($booking),
            canEdit: BookingLifecycle::canEdit($booking),
        );
    }
}
