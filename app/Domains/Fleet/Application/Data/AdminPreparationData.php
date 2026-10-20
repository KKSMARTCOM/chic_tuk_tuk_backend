<?php

namespace App\Domains\Fleet\Application\Data;

use App\Domains\Fleet\Domain\PreparationSteps;
use App\Models\VehicleContract;
use App\Shared\Data\BaseData;

/** La réponse des écritures sur les étapes : la liste à jour et ce qu'on peut encore en faire. */
final class AdminPreparationData extends BaseData
{
    public function __construct(
        /** @var AdminPreparationStepData[] */
        public array $preparationSteps,
        public bool $preparationEditable,
    ) {}

    public static function forContract(VehicleContract $contract): self
    {
        $contract->refresh();

        return new self(
            preparationSteps: $contract->preparationSteps()->with('doneBy')->get()
                ->map(fn ($step) => AdminPreparationStepData::fromModel($step))->all(),
            preparationEditable: PreparationSteps::isEditable($contract),
        );
    }
}
