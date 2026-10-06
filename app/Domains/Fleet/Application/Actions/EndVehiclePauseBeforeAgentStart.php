<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Models\Vehicle;
use Carbon\Carbon;

/**
 * Ferme la pause en cours d'un véhicule à l'arrivée d'un agent : la VEILLE de son premier
 * jour (défaut du 2026-10-06).
 *
 * ⚠️ Les bornes d'une pause sont incluses (`ContractMonthCalendar`). Fermée le jour même de
 * l'arrivée, comme le faisaient la création d'un agent et la reconduction, elle couvrait ce
 * jour, classé « immobilisation » : le premier jour de chaque nouvel agent n'était jamais
 * payé. Une pause qui commence ce jour-là ou après n'a immobilisé aucun jour avant lui :
 * elle est annulée.
 */
final class EndVehiclePauseBeforeAgentStart
{
    public function __construct(private readonly CancelPause $cancelPause) {}

    public function __invoke(Vehicle $vehicle, string $agentStartDate): void
    {
        $pause = $vehicle->activePause;
        if ($pause === null) {
            return;
        }

        $eve = Carbon::parse($agentStartDate)->startOfDay()->subDay();

        if ($eve->lt($pause->start_date->copy()->startOfDay())) {
            ($this->cancelPause)($pause);
        } else {
            $pause->update(['end_date' => $eve->toDateString()]);
        }

        $vehicle->unsetRelation('activePause');
    }
}
