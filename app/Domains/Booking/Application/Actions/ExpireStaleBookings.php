<?php

namespace App\Domains\Booking\Application\Actions;

use App\Models\Booking;
use Carbon\Carbon;

/**
 * Expire les courses en attente dépassées de 24 h (commande) — ex-
 * `BookingService::markExpiredBookings()`, déplacé sans changement le 2026-09-27.
 */
final class ExpireStaleBookings
{
    public function __construct(private readonly RecordMissedChild $recordMissedChild) {}

    public function __invoke(): int
    {
        $expiredBookings = Booking::where('status', 'pending')
            ->whereRaw('(pickup_date::date + pickup_time::time) < ?', [Carbon::now()->subHours(24)])
            ->whereNull('expired_at')
            ->get();

        foreach ($expiredBookings as $booking) {
            if ($booking->is_subscription_child) {
                ($this->recordMissedChild)($booking->id);

                continue;
            }

            $booking->update([
                'status' => 'expired',
                'expired_at' => Carbon::now(),
            ]);
        }

        return $expiredBookings->count();
    }
}
