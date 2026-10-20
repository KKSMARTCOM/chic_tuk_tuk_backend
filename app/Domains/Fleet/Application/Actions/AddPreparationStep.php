<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Domain\PreparationSteps;
use App\Models\VehicleContract;
use App\Models\VehicleContractPreparationStep;
use Illuminate\Support\Facades\DB;

/** Une étape propre au véhicule, rangée juste avant l'arrivée du premier agent (spec 2026-10-20, §4.2). */
final class AddPreparationStep
{
    public function __invoke(VehicleContract $contract, string $label): VehicleContractPreparationStep
    {
        return DB::transaction(function () use ($contract, $label) {
            $contract = VehicleContract::query()->lockForUpdate()->findOrFail($contract->id);
            PreparationSteps::assertEditable($contract);

            $arrival = $contract->preparationSteps()->where('is_agent_arrival', true)->first();
            $position = $arrival?->position ?? ((int) $contract->preparationSteps()->max('position') + 1);
            $arrival?->update(['position' => $position + 1]);

            return VehicleContractPreparationStep::create([
                'vehicle_contract_id' => $contract->id, 'label' => trim($label), 'position' => $position,
            ]);
        });
    }
}
