<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;

/**
 * Un mois du récapitulatif que reçoit un propriétaire.
 *
 * `fixedAmount` vaut `validé − charges − déficit reporté`, et n'est JAMAIS négatif
 * depuis le 2026-09-29 : un mois déficitaire s'affiche à 0 et reporte son manque
 * (`deficitCarriedOut`) sur le mois suivant, qui le reçoit en `deficitCarriedIn`. Avant,
 * le montant transposé du Blade s'affichait négatif au propriétaire.
 */
final class MonthlyPayoutData extends BaseData
{
    public function __construct(
        public string $month,
        public bool $isCurrent,
        public float $validatedAmount,
        public float $pendingAmount,
        public float $cancelledAmount,
        public float $totalCharges,
        public float $fixedAmount,
        /** Le manque des mois précédents, déduit ce mois-ci. */
        public float $deficitCarriedIn,
        /** Le manque restant, reporté sur le mois suivant. */
        public float $deficitCarriedOut,
        public int $workedDays,
        public int $agentLeaveDays,
        public int $immobilizationDays,
    ) {}
}
