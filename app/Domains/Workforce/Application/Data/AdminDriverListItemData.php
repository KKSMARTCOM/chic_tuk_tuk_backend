<?php

namespace App\Domains\Workforce\Application\Data;

use App\Models\User;
use App\Shared\Data\BaseData;

/**
 * Une ligne de la liste des agents — ex-Admin\DriverController::index().
 *
 * ⚠️ `id` est l'identifiant de l'AGENT (`drivers.id`), `userId` celui de son compte —
 * même convention que `AdminDriverLeaveSummaryData`, pour la même raison : les routes de
 * ce domaine prennent l'identifiant d'agent.
 *
 * ⚠️ `totalTrips` reprend `drivers.total_trips`, un compteur STOCKÉ (incrémenté à la
 * complétion d'une course, décrémenté à une réouverture) — pas un COUNT live des
 * réservations terminées, que porte plutôt le dossier (`bookingStats.completed`). Les
 * deux coïncident en fonctionnement normal, mais `DriversImport` permet de fixer
 * `total_trips` depuis un CSV sans aucun lien avec de vraies réservations : le champ peut
 * diverger pour un agent importé. Reproduit tel quel depuis le Blade — l'écart n'est pas
 * corrigé dans ce lot.
 */
final class AdminDriverListItemData extends BaseData
{
    public function __construct(
        public string $id,
        public string $userId,
        public ?string $name,
        public ?string $email,
        public ?string $phone,
        public ?string $licenseNumber,
        public bool $isActive,
        public bool $isAvailable,
        public ?string $vehicleNumber,
        public ?string $vehicleType,
        public int $totalTrips,
        public string $createdAt,
    ) {}

    public static function fromModel(User $user): self
    {
        $driver = $user->driver;
        $vehicle = $driver?->currentVehicle;

        return new self(
            id: $driver->id,
            userId: $user->id,
            name: $user->name,
            email: $user->email,
            phone: $user->phone,
            licenseNumber: $driver?->license_number,
            isActive: (bool) $user->is_active,
            isAvailable: (bool) $driver?->is_available,
            vehicleNumber: $vehicle?->vehicle_number,
            vehicleType: $vehicle?->vehicle_type,
            totalTrips: $driver?->total_trips ?? 0,
            createdAt: $user->created_at->toIso8601String(),
        );
    }
}
