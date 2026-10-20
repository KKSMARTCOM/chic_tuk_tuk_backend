<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;

/** La liste par défaut des étapes de préparation, dans son ordre (spec 2026-10-20, §5.1). */
final class PreparationStepSettingsData extends BaseData
{
    public function __construct(
        /** @var PreparationStepTemplateData[] */
        public array $steps,
    ) {}
}
