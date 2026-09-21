<?php

namespace App\Domains\Booking\Application\Data;

use App\Shared\Data\BaseData;

/**
 * GET /admin/dashboard — ce que la vue Blade `pages.admin.dashboard` affiche.
 *
 * Quatre compteurs généraux, trois compteurs du jour, les dix dernières réservations en
 * attente et les cinq agents les plus rémunérateurs.
 *
 * ⚠️ Le bloc « Notifications » de la vue Blade n'est PAS transposé : il est en commentaire
 * dans le source et ne contenait que du texte d'exemple figé (« 5 nouvelles réservations
 * — il y a 10 minutes »). La cloche de l'en-tête, elle, affiche de vraies notifications
 * depuis le sous-lot 3c.
 *
 * ⚠️ Les actions « Assigner un agent » et « Retirer l'agent », que la vue Blade propose
 * sur chaque réservation récente, ne sont pas ici : ce sont des écritures du domaine des
 * réservations, livrées avec lui. Jusque-là, l'espace Blade reste disponible pour cela —
 * les deux applications cohabitent pendant la migration.
 */
final class AdminDashboardData extends BaseData
{
    public function __construct(
        public int $totalBookings,
        public int $pendingBookings,
        public int $totalDrivers,
        /** Agents marqués disponibles — `drivers.is_available`. */
        public int $activeDrivers,
        /** Chiffre d'affaires : somme des `total_price` des courses terminées. */
        public float $totalRevenue,
        public int $completedToday,
        public int $inProgressToday,
        public int $cancelledToday,
        /** @var array<int, AdminRecentBookingData> les dix dernières en attente */
        public array $recentPending,
        /** @var array<int, DriverRevenueData> les cinq premiers par gains */
        public array $topDrivers,
    ) {}

    /** @param array<string, mixed> $stats la sortie de BuildAdminDashboard */
    public static function fromStats(array $stats): self
    {
        return new self(
            totalBookings: (int) $stats['total_bookings'],
            pendingBookings: (int) $stats['pending_bookings'],
            totalDrivers: (int) $stats['total_drivers'],
            activeDrivers: (int) $stats['active_drivers'],
            totalRevenue: (float) $stats['total_revenue'],
            completedToday: (int) $stats['completed_today'],
            inProgressToday: (int) $stats['in_progress_today'],
            cancelledToday: (int) $stats['cancelled_today'],
            recentPending: collect($stats['recent_pending'])
                ->map(fn ($booking) => AdminRecentBookingData::fromModel($booking))
                ->all(),
            topDrivers: collect($stats['top_drivers'])
                ->map(fn ($driver) => DriverRevenueData::fromModel($driver))
                ->all(),
        );
    }
}
