<?php

namespace App\Domains\Workforce\Application\Data;

use App\Models\Driver;
use App\Services\CommissionService;
use App\Services\DriverService;
use App\Shared\Data\BaseData;

/**
 * Le dossier complet d'UN agent, vu de l'administration — ex-Admin\DriverController::show(),
 * y compris le cadre Commissions et le cadre Revenus abonnements déjà exposés au lot
 * précédent.
 *
 * ⚠️ Ce sous-lot est en LECTURE SEULE : rien ici ne porte les boutons d'action du Blade
 * (pause, paiement, disponibilité, statut, modifier le contrat) — ils forment leur propre
 * sous-lot, comme pour les réservations.
 */
final class AdminDriverDetailData extends BaseData
{
    public function __construct(
        public string $id,
        public string $userId,
        public ?string $name,
        public ?string $email,
        public ?string $phone,
        public ?string $adresse,
        public bool $isActive,
        public bool $isAvailable,
        public ?string $agentCode,
        public ?string $agentId,
        public ?string $licenseNumber,
        public string $createdAt,
        public AdminDriverBookingStatsData $bookingStats,
        public AdminDriverCommissionStatsData $commissionStats,
        public AdminDriverSubscriptionRevenueData $subscriptionRevenue,
        public ?AdminDriverActiveContractData $activeContract,
        /** @var array<int, AdminDriverRecentBookingData> */
        public array $recentBookings,
    ) {}

    public static function fromModel(
        Driver $driver,
        DriverService $driverService,
        CommissionService $commissionService,
    ): self {
        $user = $driver->user;
        $activeContract = $driver->activeDriverContract?->load(['vehicle.owner', 'vehicleContract']);
        $vehicle = $activeContract?->vehicle;

        $bookingStats = $driverService->getDriverBookingStats($driver->id);
        $commissionStats = $commissionService->getDriverCommissions($driver->id);
        $subscriptionRevenue = $commissionService->getDriverSubscriptionRevenue($driver->id);

        // Mêmes 5 dernières courses que le Blade, triées le plus récent d'abord — voir
        // `DriverService::getDriverById()`.
        $recentBookings = $driver->bookings()
            ->orderByRaw('(pickup_date::date + pickup_time::time) DESC')
            ->take(5)
            ->get();

        return new self(
            id: $driver->id,
            userId: $driver->user_id,
            name: $user?->name,
            email: $user?->email,
            phone: $user?->phone,
            adresse: $user?->adresse,
            isActive: (bool) $user?->is_active,
            isAvailable: (bool) $driver->is_available,
            agentCode: $driver->agent_code,
            agentId: $driver->agent_id,
            licenseNumber: $driver->license_number,
            createdAt: $user->created_at->toIso8601String(),
            bookingStats: AdminDriverBookingStatsData::fromArray($bookingStats),
            commissionStats: AdminDriverCommissionStatsData::fromArray($commissionStats),
            subscriptionRevenue: AdminDriverSubscriptionRevenueData::fromArray($subscriptionRevenue),
            activeContract: $activeContract && $vehicle
                ? AdminDriverActiveContractData::fromModel($activeContract, $vehicle)
                : null,
            recentBookings: $recentBookings->map(fn ($b) => AdminDriverRecentBookingData::fromModel($b))->all(),
        );
    }
}
