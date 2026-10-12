<?php

namespace App\Domains\Fleet\Application\Data;

use App\Domains\Finance\Domain\ContractMonthCalculator;
use App\Models\Vehicle;
use App\Shared\Data\BaseData;

/**
 * Le cumul et l'historique, réunis parce que l'écran les montre ensemble.
 *
 * ⚠️ Les deux ne mesurent pas la même chose : le solde compte les jours de pause
 * d'AGENT (au calendrier, par `ContractMonthCalculator`, depuis le 2026-09-30), tandis
 * que l'historique liste les pauses du véhicule, immobilisations comprises, et les
 * pauses d'agent qui n'en ont pas (`VehiclePauseData::historyOf`).
 */
final class OwnerVehiclePausesData extends BaseData
{
    /** @param list<VehiclePauseData> $items */
    public function __construct(
        public ?OwnerContractPauseSummaryData $summary,
        public array $items,
    ) {}

    /** Attend un véhicule ayant chargé `pauses`, `driverContracts.leaveRequests`, `activeVehicleContract`, `liveVehicleContract` et `settledVehicleContract`. */
    public static function fromModel(Vehicle $vehicle): self
    {
        $contract = $vehicle->activeVehicleContract;
        $end = $vehicle->liveVehicleContract === null ? $vehicle->settledVehicleContract?->end_date : null;
        $items = VehiclePauseData::historyOf($vehicle);
        if ($end !== null) {
            // Après la fin du contrat : les pauses de l'agent qui continue ne le regardent pas (2026-10-12).
            $items = array_values(array_filter($items, fn (VehiclePauseData $p) => $p->startDate <= $end->toDateString()));
        }

        return new self(
            summary: $contract ? OwnerContractPauseSummaryData::fromContract($contract, ContractMonthCalculator::for($contract)) : null,
            items: $items,
        );
    }
}
