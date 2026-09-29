<?php

namespace App\Domains\Fleet\Presentation\Api\V1\Admin;

use App\Domains\Audit\Application\ActivityJournal;
use App\Domains\Fleet\Application\Actions\ShowVehicleContractSettings;
use App\Domains\Fleet\Application\Actions\UpdateVehicleContractSettings;
use App\Domains\Fleet\Application\Data\UpdateVehicleContractSettingsData;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Les durées et montants des contrats véhicule, réglés par l'administration. Mêmes
 * règles de try/catch que le reste de l'API v1.
 */
final class VehicleContractSettingsController
{
    public function show(Request $request, ShowVehicleContractSettings $show): JsonResponse
    {
        try {
            return response()->json($show());
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la lecture des réglages des contrats véhicule',
                'Les réglages des contrats n\'ont pas pu être chargés. Réessayez.', 'CONTRACT_SETTINGS_READ_FAILED');
        }
    }

    public function update(
        Request $request,
        UpdateVehicleContractSettingsData $data,
        UpdateVehicleContractSettings $update,
        ShowVehicleContractSettings $show,
        ActivityJournal $journal,
    ): JsonResponse {
        try {
            $before = $show()->toArray();
            $after = $update($data);
            $journal->contractTermsUpdated($before, $after->toArray());

            return response()->json($after);
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'l\'enregistrement des réglages des contrats véhicule',
                'Les réglages des contrats n\'ont pas pu être enregistrés. Réessayez.', 'CONTRACT_SETTINGS_UPDATE_FAILED');
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
