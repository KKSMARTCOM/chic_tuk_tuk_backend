<?php

namespace App\Domains\Fleet\Application\Data;

use App\Models\PreparationStepTemplate;
use App\Shared\Data\BaseData;

/** Une étape de la liste par défaut (spec 2026-10-20, §5.1). */
final class PreparationStepTemplateData extends BaseData
{
    public function __construct(
        public string $id,
        public string $label,
    ) {}

    public static function fromModel(PreparationStepTemplate $template): self
    {
        return new self(id: $template->id, label: $template->label);
    }
}
