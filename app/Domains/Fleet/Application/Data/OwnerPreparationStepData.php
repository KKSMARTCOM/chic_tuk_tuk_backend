<?php

namespace App\Domains\Fleet\Application\Data;

use App\Models\VehicleContractPreparationStep;
use App\Shared\Data\BaseData;

/** Une étape de préparation vue par le propriétaire : ni auteur, ni montant (spec 2026-10-20, §5.3). */
final class OwnerPreparationStepData extends BaseData
{
    public function __construct(
        public string $id,
        public string $label,
        public ?string $doneOn,
        public bool $hasProof,
        public bool $isAgentArrival,
    ) {}

    public static function fromModel(VehicleContractPreparationStep $step): self
    {
        return new self(
            id: $step->id,
            label: $step->label,
            doneOn: $step->done_on?->toDateString(),
            hasProof: $step->proof_path !== null,
            isAgentArrival: $step->is_agent_arrival,
        );
    }
}
