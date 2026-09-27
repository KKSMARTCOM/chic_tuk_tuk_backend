<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Models\Driver;

/**
 * Le cadre « Statistiques des courses » du dossier agent — ex-
 * `DriverService::getDriverBookingStats()`, déplacé sans changement le 2026-09-27.
 */
final class ComputeDriverBookingStats
{
    public function __invoke($driverId)
    {
        $driver = Driver::findOrFail($driverId);

        $bookings = $driver->bookings();

        return [
            'total' => (clone $bookings)->count(),
            'completed' => (clone $bookings)->where('status', 'completed')->count(),
            'cancelled' => (clone $bookings)->where('status', 'cancelled')->count(),
            'confirmed' => (clone $bookings)->where('status', 'confirmed')->count(),
            'in_progress' => (clone $bookings)->where('status', 'in_progress')->count(),
            'total_minutes' => $this->calculateTotalDrivingMinutes($driver),
            'average_rating' => $driver->rating ?? 0,
        ];
    }

    private function calculateTotalDrivingMinutes(Driver $driver)
    {
        $completedBookings = $driver->bookings()
            ->where('status', 'completed')
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->get();

        $totalMinutes = 0;

        foreach ($completedBookings as $booking) {
            $totalMinutes += $booking->started_at->diffInMinutes($booking->completed_at);
        }

        return $totalMinutes;
    }
}
