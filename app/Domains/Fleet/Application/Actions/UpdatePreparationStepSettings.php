<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\PreparationStepSettingsData;
use App\Domains\Fleet\Application\Data\UpdatePreparationStepSettingsData;
use App\Models\PreparationStepTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Remplace la liste par défaut (spec 2026-10-20, §5.1). Les contrats existants gardent leurs
 * étapes : elles ont été copiées à leur création.
 */
final class UpdatePreparationStepSettings
{
    public function __construct(private readonly ShowPreparationStepSettings $show) {}

    public function __invoke(UpdatePreparationStepSettingsData $data): PreparationStepSettingsData
    {
        DB::transaction(function () use ($data) {
            $kept = collect($data->steps)->pluck('id')->filter()->all();
            PreparationStepTemplate::query()->whereNotIn('id', $kept)->delete();

            foreach (array_values($data->steps) as $i => $step) {
                $attributes = ['label' => trim((string) $step['label']), 'position' => $i + 1];
                ($step['id'] ?? null)
                    ? PreparationStepTemplate::whereKey($step['id'])->update($attributes)
                    : PreparationStepTemplate::create($attributes);
            }
        });

        return ($this->show)();
    }
}
