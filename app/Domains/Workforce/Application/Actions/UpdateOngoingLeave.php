<?php

namespace App\Domains\Workforce\Application\Actions;

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
 * ⚠️ La disponibilité n'est réévaluée QUE dans un sens : avancer la date à aujourd'hui
 * rend l'agent indisponible, la repousser ne le rend PAS disponible.
 *
 * Ce n'est pas un oubli de transposition. Le contrôleur Blade porte bien une seconde
 * branche, mais elle exige `! $driver->hasOngoingLeave()` — or la pause qu'on corrige
 * reste `ongoing`, donc la condition est toujours fausse et la branche ne s'exécute
 * jamais. Son auteur l'avait noté : « cas limite improbable ici, gardé par sécurité ».
 *
 * On reprend ce comportement tel quel. Un agent dont la pause est repoussée au mois
 * prochain reste donc marqué indisponible d'ici là, ce qui est discutable — mais le
 * corriger serait un changement de règle métier, pas une transposition, et cela se
 * décide ailleurs.
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

            // Transposé à l'identique : seul le passage à `false` est atteignable.
            if ($debut->lte(now()->startOfDay())) {
                $pause->driver?->update(['is_available' => false]);
            }

            return $pause->refresh();
        });
    }
}
