<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Domain\PreparationSteps;
use App\Models\User;
use App\Models\VehicleContract;
use App\Models\VehicleContractPreparationStep;
use Illuminate\Support\Facades\DB;

/**
 * Cocher, décocher, dater de nouveau ou renommer une étape (spec 2026-10-20, §4.2).
 *
 * Rend ce qui a changé — le contrôleur trace et notifie d'après ce retour. « Faite » n'est
 * déclarée que sur le passage de « à faire » à « faite », lu sous verrou : deux admins qui
 * cochent ensemble ne préviennent le propriétaire qu'une fois.
 *
 * @phpstan-type Changes array{done: bool, undone: bool, redated: bool, renamed: ?string}
 */
final class UpdatePreparationStep
{
    /**
     * @param  array{label?: string, done_on?: ?string}  $input  `done_on` présent et nul = décocher
     * @return array{done: bool, undone: bool, redated: bool, renamed: ?string} `renamed` = l'ancien libellé
     */
    public function __invoke(VehicleContract $contract, VehicleContractPreparationStep $step, array $input, ?User $actor): array
    {
        return DB::transaction(function () use ($contract, $step, $input, $actor) {
            $contract = VehicleContract::query()->lockForUpdate()->findOrFail($contract->id);
            PreparationSteps::assertEditable($contract);
            $step = VehicleContractPreparationStep::query()->lockForUpdate()->findOrFail($step->id);
            $changes = ['done' => false, 'undone' => false, 'redated' => false, 'renamed' => null];

            if (array_key_exists('label', $input)) {
                PreparationSteps::assertNotAgentArrival($step, 'label');
                $label = trim((string) $input['label']);
                if ($label !== $step->label) {
                    $changes['renamed'] = $step->label;
                    $step->label = $label;
                }
            }

            if (array_key_exists('done_on', $input)) {
                PreparationSteps::assertNotAgentArrival($step, 'done_on');
                $date = $input['done_on'];
                if ($date === null && $step->done_on !== null) {
                    $changes['undone'] = true;
                    $step->fill(['done_on' => null, 'done_by' => null, 'done_at' => null]);
                    PreparationSteps::forgetProof($step);
                } elseif ($date !== null && $step->done_on === null) {
                    $changes['done'] = true;
                    $step->fill(['done_on' => $date, 'done_by' => $actor?->id, 'done_at' => now()]);
                } elseif ($date !== null && $step->done_on->toDateString() !== $date) {
                    $changes['redated'] = true;
                    $step->done_on = $date;
                }
            }

            $step->save();

            return $changes;
        });
    }
}
