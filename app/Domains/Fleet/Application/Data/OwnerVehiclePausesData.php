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
 * que l'historique liste les lignes de `vehicle_pauses`, immobilisations comprises.
 */
final class OwnerVehiclePausesData extends BaseData
{
    /** @param list<VehiclePauseData> $items */
    public function __construct(
        public ?OwnerContractPauseSummaryData $summary,
        public array $items,
    ) {}

    /** Attend un véhicule ayant chargé `pauses` et `activeVehicleContract`. */
    public static function fromModel(Vehicle $vehicle): self
    {
        $contract = $vehicle->activeVehicleContract;

        return new self(
            summary: $contract ? OwnerContractPauseSummaryData::fromContract($contract, ContractMonthCalculator::for($contract)) : null,
            items: $vehicle->pauses
                ->map(fn ($pause) => VehiclePauseData::fromModel($pause))
                ->all(),
        );
    }
}
