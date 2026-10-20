<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Domain\PreparationSteps;
use App\Models\VehicleContract;
use App\Models\VehicleContractPreparationStep;
use Illuminate\Support\Facades\DB;

/** Retirer la preuve d'une étape, fichier compris (spec 2026-10-20, §4.2). */
final class RemovePreparationProof
{
    public function __invoke(VehicleContract $contract, VehicleContractPreparationStep $step): void
    {
        DB::transaction(function () use ($contract, $step) {
            $contract = VehicleContract::query()->lockForUpdate()->findOrFail($contract->id);
            PreparationSteps::assertEditable($contract);
            $step = VehicleContractPreparationStep::query()->lockForUpdate()->findOrFail($step->id);
            PreparationSteps::forgetProof($step);
            $step->save();
        });
    }
}
