<?php

namespace App\Domains\Finance\Domain;

/**
 * Les chiffres d'un mois de contrat véhicule — ce que le récapitulatif du propriétaire et
 * la fiche de rémunération affichent.
 */
final class ContractMonthFigures
{
    public function __construct(
        public readonly string $month,
        public readonly bool $isCurrent,
        /** Au moins un jour comptabilisé. Un mois non travaillé n'a aucune charge. */
        public readonly bool $isWorked,
        public readonly int $businessDays,
        public readonly int $pauseDays,
        public readonly int $immobilizationDays,
        public readonly int $countedDays,
        public readonly float $validatedAmount,
        public readonly int $pendingCount,
        public readonly float $pendingAmount,
        public readonly float $cancelledAmount,
        /** Paiements non annulés tombant sur un jour de pause ou d'immobilisation. */
        public readonly int $paymentsOnStoppedDays,
        /** Jours comptabilisés sans aucun paiement non annulé. */
        public readonly int $countedDaysWithoutPayment,
    ) {}
}
