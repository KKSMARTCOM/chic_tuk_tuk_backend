<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Domains\Fleet\Application\Actions\ClosePause;
use App\Models\LeaveRequest;
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
 * la disponibilité de l'agent est recalculée par `SyncDriverAvailability` — une seconde
 * pause commencée le retient, et un agent parti ne redevient pas disponible.
 */
final class EndLeave
{
    public function __construct(
        private readonly ClosePause $closePause,
        private readonly SyncDriverAvailability $syncAvailability,
    ) {}

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

        // ⚠️ Clôturer, c'est constater une fin ARRIVÉE. Le 2026-10-01, une pause d'un jour
        // a été clôturée au 16 octobre, sa date de retour prévue : 22 jours comptés, et
        // les jours de l'agent suivant classés en pause jusqu'à cette date.
        if ($fin->gt(now()->startOfDay())) {
            throw new ApiException(
                422,
                'LEAVE_END_IN_FUTURE',
                "La date de fin ne peut pas être dans le futur : clôturez la pause le jour où elle se termine."
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
                ($this->closePause)($pause->vehiclePause, $fin->toDateString());
            }

            // ⚠️ Par la règle commune : une autre pause commencée le retient, une pause à
            // venir non, et un agent parti (sans contrat) ne redevient jamais disponible.
            ($this->syncAvailability)($agent);

            return $pause->refresh();
        });
    }
}
