<?php

namespace App\Domains\Fleet\Application\Data;

use App\Models\VehicleContractPreparationStep;
use App\Shared\Data\BaseData;

/** Une étape de préparation sur la fiche admin du contrat (spec 2026-10-20, §5.2). */
final class AdminPreparationStepData extends BaseData
{
    public function __construct(
        public string $id,
        public string $label,
        public int $position,
        public bool $isAgentArrival,
        public ?string $doneOn,
        public ?string $doneByName,
        public bool $hasProof,
        public ?string $proofOriginalName,
    ) {}

    public static function fromModel(VehicleContractPreparationStep $step): self
    {
        return new self(
            id: $step->id,
            label: $step->label,
            position: $step->position,
            isAgentArrival: $step->is_agent_arrival,
            doneOn: $step->done_on?->toDateString(),
            doneByName: $step->doneBy?->name,
            hasProof: $step->proof_path !== null,
            proofOriginalName: $step->proof_original_name,
        );
    }
}
