<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Models\Driver;
use App\Models\LeaveRequest;
use App\Services\VehicleService;
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
    public function __construct(private readonly VehicleService $vehicleService) {}

    public function __invoke(LeaveRequest $pause, string $dateDeDebut, int $joursDemandes): LeaveRequest
    {
        if ($pause->status !== 'ongoing') {
            throw new ApiException(
                409,
                'LEAVE_NOT_ONGOING',
                'Seule une pause en cours peut être corrigée ici.'
            );
        }

        $debut = Carbon::parse($dateDeDebut)->startOfDay();

        return DB::transaction(function () use ($pause, $debut, $joursDemandes) {
            $pause->update([
                'start_date' => $debut->toDateString(),
                'requested_days' => $joursDemandes,
            ]);

            if ($pause->vehiclePause) {
                $this->vehicleService->correctPauseDates($pause->vehiclePause, $debut->toDateString());
            }

            $agent = $pause->driver;

            if ($agent) {
                $agent->update(['is_available' => ! $this->unePauseACommence($agent)]);
            }

            return $pause->refresh();
        });
    }

    /**
     * Une pause de cet agent a-t-elle RÉELLEMENT commencé ?
     *
     * `hasOngoingLeave()` ne suffit pas : il répond oui pour une pause `ongoing` dont la
     * date de début est encore à venir, et bloquerait l'agent alors qu'il peut rouler.
     * C'est la date qui décide, pas le statut.
     */
    private function unePauseACommence(Driver $agent): bool
    {
        return $agent->leaveRequests()
            ->where('status', 'ongoing')
            ->whereDate('start_date', '<=', now()->startOfDay())
            ->exists();
    }
}
