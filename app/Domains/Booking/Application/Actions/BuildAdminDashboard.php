<?php

namespace App\Domains\Booking\Application\Actions;

use App\Models\Booking;
use App\Models\Driver;

/**
 * Le tableau de bord de l'administration — ex-DashboardController::admin().
 *
 * Transposé requête par requête depuis le contrôleur Blade, sans changer ce qui est
 * compté. Les colonnes de date diffèrent d'un compteur du jour à l'autre — `completed_at`,
 * `started_at`, `cancelled_at` — et ce n'est pas une inattention : chaque compteur date
 * l'événement qu'il nomme, pas la course.
 */
final class BuildAdminDashboard
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return [
            'total_bookings' => Booking::count(),
            'pending_bookings' => Booking::where('status', 'pending')->count(),
            'total_drivers' => Driver::count(),
            'active_drivers' => Driver::where('is_available', true)->count(),
            'total_revenue' => Booking::where('status', 'completed')->sum('total_price'),

            // ⚠️ Chaque compteur du jour porte sur SA propre colonne d'horodatage : une
            // course terminée aujourd'hui a pu être démarrée hier, et la compter sur
            // `started_at` la ferait disparaître du compteur « complétées ».
            'completed_today' => Booking::where('status', 'completed')->whereDate('completed_at', today())->count(),
            'in_progress_today' => Booking::where('status', 'in_progress')->whereDate('started_at', today())->count(),
            'cancelled_today' => Booking::where('status', 'cancelled')->whereDate('cancelled_at', today())->count(),

            'recent_pending' => $this->reservationsEnAttente(),
            'top_drivers' => $this->meilleursAgents(),
        ];
    }

    /** Les dix dernières réservations en attente, les plus récentes d'abord. */
    private function reservationsEnAttente()
    {
        // `driver.user` en plus du contrôleur Blade : la vue y affiche le nom de l'agent,
        // et sans ce chargement c'est une requête par ligne.
        return Booking::with(['user', 'driver.user'])
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->take(10)
            ->get();
    }

    /**
     * Les cinq agents aux plus gros gains, avec leur commission DUE.
     *
     * ⚠️ `commission_due` n'est pas une colonne : le contrôleur Blade la pose à la main
     * après la requête, parce qu'elle croise deux sommes agrégées séparément. On garde
     * ce calcul plutôt que de le déplacer en SQL — le déplacer changerait les arrondis
     * sur des décimales, et ce tableau porte sur de l'argent.
     */
    private function meilleursAgents()
    {
        $agents = Driver::with('user')
            ->whereHas('bookings', fn ($q) => $q->where('status', 'completed')->where('driver_earning', '>', 0))
            ->withSum(
                ['bookings' => fn ($q) => $q->where('status', 'completed')->where('driver_earning', '>', 0)],
                'driver_earning'
            )
            ->withSum('commissions', 'amount')
            ->withSum(['payments' => fn ($q) => $q->where('payment_type', 'commission')], 'amount')
            ->orderByDesc('bookings_sum_driver_earning')
            ->limit(5)
            ->get();

        foreach ($agents as $agent) {
            $agent->commission_due = ($agent->commissions_sum_amount ?? 0) - ($agent->payments_sum_amount ?? 0);
        }

        return $agents;
    }
}
