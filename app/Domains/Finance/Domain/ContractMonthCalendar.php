<?php

namespace App\Domains\Finance\Domain;

use Carbon\Carbon;

/**
 * Le classement des jours ouvrés d'une période : pause, immobilisation, comptabilisé.
 *
 * Chaque jour est classé UNE fois, dans cet ordre : pause d'agent (motif `agent_leave`),
 * puis immobilisation (tout autre motif), sinon comptabilisé. C'est ce classement par jour
 * qui empêche le double comptage : l'ancien récapitulatif additionnait les pauses d'agent et
 * leurs pauses véhicule automatiques, et comptait donc les mêmes jours deux fois.
 *
 * Classe pure : elle reçoit des intervalles déjà chargés. `ContractMonthCalculator` lui
 * passe les pauses véhicule ET les pauses d'agent (spec §3.2), ces dernières sous le motif
 * `agent_leave`. Jour ouvré = du lundi au vendredi, au calendrier, sans jours fériés
 * (spec §10).
 */
final class ContractMonthCalendar
{
    /** @param  iterable<object{start_date: Carbon, end_date: ?Carbon, reason_type: string}>  $pauses */
    public static function classify(Carbon $from, Carbon $to, iterable $pauses): MonthDays
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();
        $pauses = collect($pauses);

        $business = $pause = $immobilization = 0;
        $counted = $stopped = [];

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            if ($day->isWeekend()) {
                continue;
            }

            $business++;
            // Une pause sans fin couvre jusqu'à la borne : c'est une pause en cours.
            $covering = $pauses->filter(fn ($p) => $p->start_date->copy()->startOfDay()->lte($day)
                && ($p->end_date === null || $p->end_date->copy()->startOfDay()->gte($day)));

            if ($covering->contains('reason_type', 'agent_leave')) {
                $pause++;
                $stopped[] = $day->toDateString();
            } elseif ($covering->isNotEmpty()) {
                $immobilization++;
                $stopped[] = $day->toDateString();
            } else {
                $counted[] = $day->toDateString();
            }
        }

        return new MonthDays($business, $pause, $immobilization, count($counted), $counted, $stopped);
    }
}
