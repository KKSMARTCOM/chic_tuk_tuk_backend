<?php

namespace App\Domains\Finance\Domain;

use Carbon\Carbon;
use Illuminate\Support\Collection;

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
            $class = self::classifyDay($day, $pauses);
            if ($class === 'weekend') {
                continue;
            }

            $business++;
            if ($class === 'pause') {
                $pause++;
                $stopped[] = $day->toDateString();
            } elseif ($class === 'immobilization') {
                $immobilization++;
                $stopped[] = $day->toDateString();
            } else {
                $counted[] = $day->toDateString();
            }
        }

        return new MonthDays($business, $pause, $immobilization, count($counted), $counted, $stopped);
    }

    /** La classe d'UN jour : la règle unique, partagée avec `ContractPaymentPlanner`. */
    public static function classifyDay(Carbon $day, Collection $pauses): string
    {
        if ($day->isWeekend()) {
            return 'weekend';
        }
        // Une pause sans fin couvre jusqu'à la borne : c'est une pause en cours.
        $covering = $pauses->filter(fn ($p) => $p->start_date->copy()->startOfDay()->lte($day)
            && ($p->end_date === null || $p->end_date->copy()->startOfDay()->gte($day)));

        return match (true) {
            $covering->contains('reason_type', 'agent_leave') => 'pause',
            $covering->isNotEmpty() => 'immobilization',
            default => 'counted',
        };
    }
}
