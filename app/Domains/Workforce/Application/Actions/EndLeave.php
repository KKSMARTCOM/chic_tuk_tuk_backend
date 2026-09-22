<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Models\LeaveRequest;
use App\Services\VehicleService;
use App\Shared\Http\ApiException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Clôturer une pause en cours — ex-Admin\LeaveController::endLeave().
 *
 * ⚠️ Les jours EFFECTIFS sont recomptés en jours OUVRÉS entre le début et la fin réelle,
 * et non repris des jours demandés : une pause écourtée n'a pas consommé ce qui avait été
 * demandé, et c'est ce décompte qui alimente le solde.
 *
 * ⚠️ Trois effets en plus du statut : la pause véhicule est clôturée à la même date, et
 * l'agent redevient disponible — mais seulement s'il ne lui reste AUCUNE autre pause en
 * cours. Le rendre disponible sans cette vérification le remettrait en service alors
 * qu'une seconde pause court encore.
 */
final class EndLeave
{
    public function __construct(private readonly VehicleService $vehicleService) {}

    public function __invoke(LeaveRequest $pause, string $dateDeFin): LeaveRequest
    {
        if ($pause->status !== 'ongoing') {
            throw new ApiException(409, 'LEAVE_NOT_ONGOING', "Cette pause n'est pas en cours.");
        }

        $fin = Carbon::parse($dateDeFin)->startOfDay();

        if ($fin->lt($pause->start_date)) {
            throw new ApiException(
                422,
                'LEAVE_END_BEFORE_START',
                'La date de fin ne peut pas précéder la date de début.'
            );
        }

        return DB::transaction(function () use ($pause, $fin) {
            $joursEffectifs = LeaveRequest::countBusinessDays($pause->start_date, $fin);

            $pause->update([
                'end_date' => $fin->toDateString(),
                'effective_days' => $joursEffectifs,
                'status' => 'completed',
            ]);

            $agent = $pause->driver;

            // ⚠️ Compteur indicatif seulement : `leave_days_used` n'est plus la source de
            // vérité des jours pris depuis le 2026-09-21 — `getLeaveDaysTaken()` recompte
            // depuis les pauses terminées. On continue de l'alimenter pour les écrans
            // Blade qui le lisent encore par l'intermédiaire du modèle.
            $agent->markLeaveDaysUsed($joursEffectifs);

            if ($pause->vehiclePause) {
                $this->vehicleService->endPause($pause->vehiclePause, $fin->toDateString());
            }

            // ⚠️ Seulement si plus AUCUNE pause ne court : sans cette condition, clôturer
            // l'une remettrait l'agent en service alors qu'une autre est en cours.
            if (! $agent->hasOngoingLeave()) {
                $agent->update(['is_available' => true]);
            }

            return $pause->refresh();
        });
    }
}
