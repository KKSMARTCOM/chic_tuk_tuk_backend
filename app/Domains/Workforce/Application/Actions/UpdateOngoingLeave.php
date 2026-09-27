<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Models\Driver;
use App\Models\LeaveRequest;
use App\Models\VehiclePause;
use App\Shared\Http\ApiException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Corriger une pause EN COURS — ex-Admin\LeaveController::updateOngoingLeave().
 *
 * ⚠️ La correction se répercute sur la pause VÉHICULE liée. Les laisser diverger est le
 * défaut le plus coûteux de cet écran : le propriétaire verrait son tricycle immobilisé
 * à une date, l'agent en pause à une autre, et personne ne saurait laquelle fait foi.
 *
 * ⚠️ La disponibilité suit la DATE, dans les deux sens — corrigé le 2026-09-22 sur
 * décision explicite.
 *
 * Le contrôleur Blade ne la réévaluait que vers l'indisponibilité : sa seconde branche
 * exigeait `! $driver->hasOngoingLeave()`, toujours faux puisque la pause qu'on corrige
 * reste `ongoing`. Un agent dont la pause était repoussée au mois prochain restait donc
 * bloqué d'ici là, sans pouvoir prendre de course.
 *
 * Rendre la règle symétrique ne crée pas d'état nouveau : `AddOngoingLeave` et
 * `ApproveLeaveRequest` laissent déjà l'agent disponible quand la pause commence plus
 * tard. « Pause `ongoing` à début futur, agent disponible » est donc une situation que
 * le système accepte déjà — la correction s'y conforme au lieu de faire exception.
 *
 * ⚠️ Mais pas aveuglément : une AUTRE pause peut avoir réellement commencé. On ne rend
 * disponible que si aucune pause en cours n'a débuté. Sans cette vérification, corriger
 * une pause en remettrait l'agent en service alors qu'une seconde le retient.
 */
final class UpdateOngoingLeave
{
    public function __invoke(LeaveRequest $leave, string $dateDeDebut, int $joursDemandes): LeaveRequest
    {
        if ($leave->status !== 'ongoing') {
            throw new ApiException(
                409,
                'LEAVE_NOT_ONGOING',
                'Seule une pause en cours peut être corrigée ici.'
            );
        }

        $debut = Carbon::parse($dateDeDebut)->startOfDay();

        return DB::transaction(function () use ($leave, $debut, $joursDemandes) {
            $leave->update([
                'start_date' => $debut->toDateString(),
                'requested_days' => $joursDemandes,
            ]);

            if ($leave->vehiclePause) {
                $this->correctPauseDates($leave->vehiclePause, $debut->toDateString());
            }

            $driver = $leave->driver;

            if ($driver) {
                $driver->update(['is_available' => ! $this->aLeaveHasStarted($driver)]);
            }

            return $leave->refresh();
        });
    }

    /**
     * Une pause de cet agent a-t-elle RÉELLEMENT commencé ?
     *
     * `hasOngoingLeave()` ne suffit pas : il répond oui pour une pause `ongoing` dont la
     * date de début est encore à venir, et bloquerait l'agent alors qu'il peut rouler.
     * C'est la date qui décide, pas le statut.
     */
    private function aLeaveHasStarted(Driver $driver): bool
    {
        return $driver->leaveRequests()
            ->where('status', 'ongoing')
            ->whereDate('start_date', '<=', now()->startOfDay())
            ->exists();
    }

    /**
     * Corriger la date de début (et éventuellement la date de fin) d'une pause véhicule existante,
     * sans en créer une nouvelle.
     */
    private function correctPauseDates(VehiclePause $pause, string $startDate, ?string $endDate = null): VehiclePause
    {
        return DB::transaction(function () use ($pause, $startDate, $endDate) {
            $pause->update([
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);

            $vehicle = $pause->vehicle;
            $today = Carbon::today();
            $start = Carbon::parse($startDate)->startOfDay();
            $end = $endDate ? Carbon::parse($endDate)->startOfDay() : null;

            $isCurrentlyPaused = $start->lte($today) && (! $end || $end->gte($today));

            // Le véhicule doit refléter l'état réel après correction
            if ($isCurrentlyPaused) {
                $vehicle->update(['is_active' => false]);
            } elseif (! $vehicle->activePause) {
                $vehicle->update(['is_active' => true]);
            }

            return $pause->refresh();
        });
    }
}
