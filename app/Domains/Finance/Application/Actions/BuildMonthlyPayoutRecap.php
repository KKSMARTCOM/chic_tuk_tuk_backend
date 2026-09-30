<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Application\Data\MonthlyPayoutData;
use App\Domains\Finance\Domain\ContractMonthCalculator;
use App\Models\VehicleContract;
use Illuminate\Support\Collection;

/**
 * Le récapitulatif mensuel d'un contrat véhicule.
 *
 * Depuis le 2026-09-30, les jours viennent de `ContractMonthCalculator` : au calendrier,
 * chaque jour classé une fois — l'ancien calcul additionnait les pauses d'agent et leurs
 * pauses véhicule automatiques, et comptait donc ces jours deux fois. Les mois vont du
 * début du contrat au mois courant, mois non travaillés compris.
 *
 * Règles gardées du code d'origine : le mois courant figure toujours, les mois futurs sont
 * exclus, et le mois courant s'arrête à aujourd'hui.
 *
 * ⚠️ Le déficit se reporte (2026-09-29), dans l'ordre CHRONOLOGIQUE, avant le tri
 * d'affichage — mais seulement sur les mois TRAVAILLÉS : un mois sans jour comptabilisé
 * n'a aucune charge. Le lot 2 remplace ce report par le compte de charges.
 */
final class BuildMonthlyPayoutRecap
{
    /** @return Collection<int, MonthlyPayoutData> */
    public function __invoke(VehicleContract $contract): Collection
    {
        $calculator = ContractMonthCalculator::for($contract);
        $carriedIn = 0.0;

        return collect($calculator->monthKeys())
            ->map(function (string $monthKey) use ($calculator, $contract, &$carriedIn) {
                $figures = $calculator->month($monthKey);
                $charges = $figures->isWorked ? $contract->total_charges : 0.0;

                $balance = $figures->validatedAmount - $charges - $carriedIn;
                $deficitCarriedIn = $carriedIn;
                $carriedIn = max(0.0, -$balance);

                return new MonthlyPayoutData(
                    month: $monthKey,
                    isCurrent: $figures->isCurrent,
                    isWorked: $figures->isWorked,
                    businessDays: $figures->businessDays,
                    countedDays: $figures->countedDays,
                    agentLeaveDays: $figures->pauseDays,
                    immobilizationDays: $figures->immobilizationDays,
                    validatedAmount: $figures->validatedAmount,
                    pendingAmount: $figures->pendingAmount,
                    cancelledAmount: $figures->cancelledAmount,
                    totalCharges: $charges,
                    fixedAmount: max(0.0, $balance),
                    deficitCarriedIn: $deficitCarriedIn,
                    deficitCarriedOut: $carriedIn,
                );
            })
            ->sortByDesc('month')
            ->values();
    }
}
