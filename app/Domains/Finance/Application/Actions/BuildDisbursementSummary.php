<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Application\Data\DisbursementSummaryData;
use App\Models\OwnerDisbursement;
use App\Models\RemunerationStatement;

/**
 * Le SEUL calcul des trois chiffres du versement (spec 2026-10-22-versement-proprietaire, §2) :
 * le plafond, l'admin et l'espace propriétaire le lisent. Cumul revenu net = somme des
 * `balance_due` des fiches validées ; un mois sans fiche ajoute 0.
 */
final class BuildDisbursementSummary
{
    /** @param  iterable<string>  $contractIds */
    public function __invoke(iterable $contractIds): DisbursementSummaryData
    {
        $ids = collect($contractIds)->values()->all();

        $netEarned = (float) RemunerationStatement::query()
            ->whereIn('vehicle_contract_id', $ids)->where('status', 'validated')->sum('balance_due');
        $disbursed = (float) OwnerDisbursement::query()
            ->whereIn('vehicle_contract_id', $ids)->where('status', 'recorded')->sum('amount');

        return new DisbursementSummaryData(
            netEarned: round($netEarned, 2),
            disbursed: round($disbursed, 2),
            outstanding: round($netEarned - $disbursed, 2),
        );
    }
}
