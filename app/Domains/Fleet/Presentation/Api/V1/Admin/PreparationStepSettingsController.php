<?php

namespace App\Domains\Fleet\Presentation\Api\V1\Admin;

use App\Domains\Audit\Application\ActivityJournal;
use App\Domains\Fleet\Application\Actions\ShowPreparationStepSettings;
use App\Domains\Fleet\Application\Actions\UpdatePreparationStepSettings;
use App\Domains\Fleet\Application\Data\UpdatePreparationStepSettingsData;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** La liste par défaut des étapes de préparation (spec 2026-10-20, §5.1), sous `manage-business-settings`. */
final class PreparationStepSettingsController
{
    public function show(Request $request, ShowPreparationStepSettings $show): JsonResponse
    {
        try {
            return response()->json($show());
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la lecture des étapes de préparation',
                'Les étapes de préparation n\'ont pas pu être chargées. Réessayez.', 'PREPARATION_SETTINGS_READ_FAILED');
        }
    }

    public function update(
        Request $request,
        UpdatePreparationStepSettingsData $data,
        UpdatePreparationStepSettings $update,
        ShowPreparationStepSettings $show,
        ActivityJournal $journal,
    ): JsonResponse {
        try {
            $steps = fn ($settings) => collect($settings->steps)->map(fn ($s) => ['id' => $s->id, 'label' => $s->label])->all();
            $before = $steps($show());
            $after = $update($data);
            $journal->preparationStepsUpdated($before, $steps($after));

            return response()->json($after);
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'l\'enregistrement des étapes de préparation',
                'Les étapes de préparation n\'ont pas pu être enregistrées. Réessayez.', 'PREPARATION_SETTINGS_UPDATE_FAILED');
        }
    }

    private function failure(\Throwable $e, Request $request, string $what, string $message, string $code): JsonResponse
    {
        Log::error("Erreur lors de {$what} : ".$e->getMessage(), [
            'exception' => $e,
            'user_id' => $request->user()?->id,
        ]);

        return response()->json(['message' => $message, 'code' => $code], 500);
    }
}
