<?php

namespace App\Domains\Finance\Domain;

use Carbon\Carbon;

/**
 * Le classement des jours d'une période pour UN contrat agent (spec 2026-10-01, §3.2).
 *
 * Classe pure : elle reçoit les pauses et les paiements déjà chargés. Ordre : hors contrat,
 * week-end, pause d'agent, immobilisation (la règle de `ContractMonthCalendar`), puis déjà
 * payé, annulé, à générer. Un jour d'arrêt reste un jour d'arrêt même s'il porte un
 * paiement : ce paiement est une anomalie de la fiche, pas un jour payé.
 */
final class ContractPaymentPlanner
{
    public const OUT_OF_CONTRACT = 'out_of_contract';

    public const WEEKEND = 'weekend';

    public const AGENT_PAUSE = 'agent_pause';

    public const IMMOBILIZED = 'immobilized';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    public const TO_GENERATE = 'to_generate';

    public const CLASSES = [self::OUT_OF_CONTRACT, self::WEEKEND, self::AGENT_PAUSE, self::IMMOBILIZED, self::PAID, self::CANCELLED, self::TO_GENERATE];

    /**
     * @param  iterable<object{start_date: Carbon, end_date: ?Carbon, reason_type: string}>  $pauses
     * @param  iterable<object{id: string, payment_date: Carbon, status: string}>  $payments
     */
    public static function plan(Carbon $from, Carbon $to, Carbon $contractStart, ?Carbon $contractEnd, Carbon $today, iterable $pauses, iterable $payments): PaymentPlan
    {
        $pauses = collect($pauses);
        $byDate = collect($payments)->groupBy(fn ($p) => $p->payment_date->toDateString());
        $start = $contractStart->copy()->startOfDay();
        $last = $today->copy()->startOfDay();
        $end = $contractEnd?->copy()->startOfDay();
        if ($end !== null && $end->lt($last)) {
            $last = $end;
        }

        $days = [];
        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            $date = $day->toDateString();

            if ($day->lt($start) || $day->gt($last)) {
                $days[] = new PlannedDay($date, self::OUT_OF_CONTRACT);

                continue;
            }

            $calendar = ContractMonthCalendar::classifyDay($day, $pauses);
            if ($calendar !== 'counted') {
                $days[] = new PlannedDay($date, match ($calendar) {
                    'weekend' => self::WEEKEND,
                    'pause' => self::AGENT_PAUSE,
                    default => self::IMMOBILIZED,
                });

                continue;
            }

            $onDay = $byDate->get($date, collect());
            $live = $onDay->first(fn ($p) => $p->status !== 'cancelled');
            $days[] = match (true) {
                $live !== null => new PlannedDay($date, self::PAID, $live->id),
                $onDay->isNotEmpty() => new PlannedDay($date, self::CANCELLED, $onDay->first()->id),
                default => new PlannedDay($date, self::TO_GENERATE),
            };
        }

        return new PaymentPlan($days);
    }
}
