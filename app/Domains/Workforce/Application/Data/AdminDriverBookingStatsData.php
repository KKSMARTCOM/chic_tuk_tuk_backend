<?php

namespace App\Domains\Workforce\Application\Data;

use App\Shared\Data\BaseData;

/** Le cadre « Statistiques des courses » du dossier agent — DriverService::getDriverBookingStats(). */
final class AdminDriverBookingStatsData extends BaseData
{
    public function __construct(
        public int $total,
        public int $completed,
        public int $cancelled,
        public int $confirmed,
        public int $inProgress,
        public int $totalMinutes,
        public float $averageRating,
    ) {}

    public static function fromArray(array $stats): self
    {
        return new self(
            total: $stats['total'],
            completed: $stats['completed'],
            cancelled: $stats['cancelled'],
            confirmed: $stats['confirmed'],
            inProgress: $stats['in_progress'],
            totalMinutes: $stats['total_minutes'],
            averageRating: (float) $stats['average_rating'],
        );
    }
}
