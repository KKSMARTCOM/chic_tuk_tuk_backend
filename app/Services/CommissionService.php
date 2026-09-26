<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Commission;
use App\Models\Driver;
use App\Models\Payment;
use App\Shared\Http\ApiException;

class CommissionService
{
    public function create(array $data)
    {
        $commission = Commission::create([
            'driver_id'       => $data['driver_id'],
            'booking_id'      => $data['booking_id'],
            'amount'          => $data['amount'],
            'status'          => $data['status'],
            'date'            => $data['date'],
        ]);

        return $commission;
    }

    public function getAllCommissions($filters = [])
    {
        $query = Commission::query()
            ->with(['driver.user', 'booking'])
            ->latest();

        if (isset($filters['driver_id']) && !empty($filters['driver_id'])) {
            $query->where('driver_id', $filters['driver_id']);
        }

        // Groupé (2026-09-26) : sans parenthèses, le `orWhereHas` échappait au filtre
        // d'agent, et un numéro de course d'un autre agent remontait quand même.
        if (isset($filters['search']) && !empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->whereHas('driver.user', fn ($u) => $u->where('name', 'ilike', '%' . $search . '%'))
                    ->orWhereHas('booking', fn ($b) => $b->where('booking_number', 'ilike', '%' . $search . '%'));
            });
        }

        return $query->latest()->get();
    }

    public function getCommissionStats()
    {
        $totalRevenue = Commission::where('status', 'active')->sum('amount');
        $totalCommissionsCount = Commission::count();

        return [
            'total_revenue' => $totalRevenue,
            'total_count' => $totalCommissionsCount,
        ];
    }

    public function getDriverCommissions(string $driverId)
    {
        $driver = Driver::with('user')->findOrFail($driverId);

        $driverEarning = Booking::where('driver_id', $driverId)
            ->where('status', 'completed')
            ->sum('driver_earning');

        return [
            'driver' => $driver,
            'driver_earning' => $driverEarning,
            'commissions_count' => $driver->commissions()->count(),
            // Commissions DUES et paiements VALIDÉS seulement (2026-09-26) : les annulés
            // comptaient des deux côtés.
            'paid_revenue' => $paid = (float) $driver->payments()->where('payment_type', 'commission')->where('status', 'completed')->sum('amount'),
            'unpaid_revenue' => (float) $driver->commissions()->where('status', 'active')->sum('amount') - $paid,
        ];
    }

    /**
     * Annule une commission : elle ne compte plus dans ce que l'agent doit, et reste
     * visible. Décidé le 2026-09-26 — le Blade la supprimait définitivement.
     */
    public function cancelCommission(string $commissionId): Commission
    {
        $commission = Commission::findOrFail($commissionId);

        if ($commission->status === 'cancelled') {
            throw new ApiException(409, 'COMMISSION_ALREADY_CANCELLED', 'Cette commission est déjà annulée.');
        }

        $commission->update(['status' => 'cancelled']);

        return $commission->refresh();
    }

    /**
     * Revenu d'un agent sur les courses d'abonnement (mère ou enfants) qu'il a
     * terminées, réparti par abonnement.
     *
     * Un abonnement paie l'agence directement — pas l'agent au comptant, contrairement
     * à une course simple — donc ce que l'agent a gagné dessus n'est ni une commission
     * ni un dû qu'il reverse : c'est une somme que l'agence lui DOIT, réglée par les
     * paiements de type `subscription_revenue`.
     *
     * Le filtrage passe par `is_subscription_parent`/`is_subscription_child`, les mêmes
     * accessors que le reste du domaine (voir `Booking`) : un simple aller-retour porte
     * lui aussi un `parent_booking_id`, mais son parent n'est pas récurrent, et ces deux
     * accessors sont ce qui l'exclut correctement.
     */
    public function getDriverSubscriptionRevenue(string $driverId): array
    {
        $bookings = Booking::where('driver_id', $driverId)
            ->where('status', 'completed')
            ->where(function ($query) {
                $query->where('is_recurring', true)->orWhereNotNull('parent_booking_id');
            })
            ->with('parentBooking')
            ->get()
            ->filter(fn ($booking) => $booking->is_subscription_parent || $booking->is_subscription_child);

        $subscriptions = $bookings
            ->groupBy(fn ($booking) => $booking->is_subscription_parent ? $booking->id : $booking->parent_booking_id)
            ->map(function ($group) {
                $reference = $group->first()->is_subscription_parent
                    ? $group->first()
                    : $group->first()->parentBooking;

                return [
                    'subscription_id' => $reference->id,
                    'booking_number' => $reference->booking_number,
                    'bookings_count' => $group->count(),
                    'amount' => (float) $group->sum('driver_earning'),
                ];
            })
            ->values();

        $totalDue = (float) $subscriptions->sum('amount');
        $totalPaid = (float) Payment::where('driver_id', $driverId)
            ->where('payment_type', 'subscription_revenue')
            ->where('status', 'completed')
            ->sum('amount');

        return [
            'subscriptions' => $subscriptions,
            'total_due' => $totalDue,
            'total_paid' => $totalPaid,
            'balance_due' => $totalDue - $totalPaid,
        ];
    }
}
