<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Domain\PreparationSteps;
use App\Models\VehicleContract;
use App\Models\VehicleContractPreparationStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Retirer une étape qui ne concerne pas ce véhicule ; une étape faite se décoche d'abord (spec 2026-10-20, §4.2). */
final class RemovePreparationStep
{
    public function __invoke(VehicleContract $contract, VehicleContractPreparationStep $step): void
    {
        DB::transaction(function () use ($contract, $step) {
            $contract = VehicleContract::query()->lockForUpdate()->findOrFail($contract->id);
            PreparationSteps::assertEditable($contract);
            $step = VehicleContractPreparationStep::query()->lockForUpdate()->findOrFail($step->id);
            PreparationSteps::assertNotAgentArrival($step, 'step');
            if ($step->done_on !== null) {
                throw ValidationException::withMessages(['step' => 'Décochez d\'abord cette étape.']);
            }

            PreparationSteps::forgetProof($step);
            $step->delete();
        });
    }
}
