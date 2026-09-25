<?php

namespace App\Domains\Fleet\Presentation\Api\V1\Admin;

use App\Domains\Fleet\Application\Actions\ShowVehicleContractDefaults;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Les contrats propriétaire-véhicule, vus de l'administration. Ne sert pour l'instant
 * que leurs valeurs par défaut ; les écrans de contrats véhicule (F3) s'y ajouteront.
 */
final class VehicleContractController
{
    public function defaults(Request $request, ShowVehicleContractDefaults $show): JsonResponse
    {
        try {
            return response()->json($show());
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Erreur lors des valeurs par défaut des contrats véhicule : '.$e->getMessage(), [
                'exception' => $e,
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'Les valeurs par défaut du contrat n\'ont pas pu être chargées. Réessayez.',
                'code' => 'VEHICLE_CONTRACT_DEFAULTS_FAILED',
            ], 500);
        }
    }
}
