<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\PreparationStepSettingsData;
use App\Domains\Fleet\Application\Data\PreparationStepTemplateData;
use App\Models\PreparationStepTemplate;

/** La liste par défaut des étapes de préparation (spec 2026-10-20, §5.1). */
final class ShowPreparationStepSettings
{
    public function __invoke(): PreparationStepSettingsData
    {
        return new PreparationStepSettingsData(
            steps: PreparationStepTemplate::query()->orderBy('position')->get()
                ->map(fn (PreparationStepTemplate $template) => PreparationStepTemplateData::fromModel($template))
                ->all(),
        );
    }
}
