<?php

namespace App\Domains\Finance\Domain;

/**
 * Les jours ouvrés d'un mois de contrat, chacun classé une seule fois.
 *
 * `countedDates` et `stoppedDates` servent aux anomalies de la fiche : un paiement sur un
 * jour d'arrêt, un jour comptabilisé sans paiement.
 */
final class MonthDays
{
    /**
     * @param  list<string>  $countedDates  dates `Y-m-d`
     * @param  list<string>  $stoppedDates  dates `Y-m-d`, pauses et immobilisations
     */
    public function __construct(
        public readonly int $businessDays,
        public readonly int $pauseDays,
        public readonly int $immobilizationDays,
        public readonly int $countedDays,
        public readonly array $countedDates,
        public readonly array $stoppedDates,
    ) {}
}
