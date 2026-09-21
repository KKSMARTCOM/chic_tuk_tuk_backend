<?php

namespace App\Domains\Booking\Application\Data;

use App\Models\Booking;
use App\Shared\Data\BaseData;
use Carbon\Carbon;

/**
 * Une réservation en attente, telle que le tableau de bord admin la liste.
 *
 * ⚠️ Contrairement à `AvailableBookingData`, qui sert les agents, celle-ci PORTE les
 * coordonnées du client : un administrateur voit tout le dossier, c'est le sens de son
 * rôle. La règle de confidentialité du sous-lot 3a vise les agents, qui ne doivent rien
 * savoir d'une course avant de l'avoir acceptée — elle ne s'applique pas ici.
 */
final class AdminRecentBookingData extends BaseData
{
    public function __construct(
        public string $id,
        public string $bookingNumber,
        public ?string $clientName,
        public ?string $phone,
        public string $fromLocation,
        public string $toLocation,
        /** Heure MURALE, sans décalage — voir la règle du projet sur `pickup_at`. */
        public string $pickupAt,
        public float $totalPrice,
        /** L'agent affecté, quand il y en a un. Une course en attente n'en a pas. */
        public ?string $driverName,
        public string $createdAt,
    ) {}

    public static function fromModel(Booking $booking): self
    {
        return new self(
            id: $booking->id,
            bookingNumber: $booking->booking_number,
            clientName: $booking->client_name ?? $booking->user?->name,
            phone: $booking->phone ?? $booking->user?->phone,
            fromLocation: $booking->from_location,
            toLocation: $booking->to_location,
            // Date et heure murales concaténées, jamais un instant : l'application tourne
            // en UTC et le Bénin est à UTC+1, donc suffixer un décalage déplacerait
            // l'affichage d'une heure.
            pickupAt: $booking->pickup_date?->format('Y-m-d').'T'
                .Carbon::parse($booking->pickup_time)->format('H:i:s'),
            totalPrice: (float) $booking->total_price,
            driverName: $booking->driver?->user?->name,
            createdAt: $booking->created_at->toIso8601String(),
        );
    }
}
