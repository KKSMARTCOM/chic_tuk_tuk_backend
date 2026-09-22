<?php

namespace App\Domains\Workforce\Presentation\Api\V1\Driver;

use App\Domains\Workforce\Application\Actions\ListDriverLeaves;
use App\Domains\Workforce\Application\Actions\RequestLeave;
use App\Domains\Workforce\Application\Data\DriverLeavesData;
use App\Domains\Workforce\Application\Data\LeaveRequestData;
use App\Domains\Workforce\Application\Data\RequestLeaveData;
use App\Models\Driver;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Les pauses de l'espace agent.
 *
 * Mêmes règles de try/catch que le BookingController du sous-lot 3a : ValidationException,
 * ApiException et ModelNotFoundException relancées EN PREMIER, \Throwable et non
 * \Exception, et le message d'exception au journal seulement.
 */
final class LeaveController
{
    public function index(Request $request, ListDriverLeaves $lister): JsonResponse
    {
        try {
            return response()->json(
                DriverLeavesData::fromArray($lister($this->agent($request)))
            );
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Erreur lors de la lecture des pauses : '.$e->getMessage(), [
                'exception' => $e,
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'Vos pauses n\'ont pas pu être chargées. Réessayez.',
                'code' => 'DRIVER_LEAVES_FAILED',
            ], 500);
        }
    }

    public function store(Request $request, RequestLeaveData $data, RequestLeave $demander): JsonResponse
    {
        try {
            $demande = $demander($this->agent($request), $data->startDate, $data->requestedDays);

            return response()->json(LeaveRequestData::fromModel($demande), 201);
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Erreur lors d\'une demande de pause : '.$e->getMessage(), [
                'exception' => $e,
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'Votre demande n\'a pas pu être envoyée. Réessayez.',
                'code' => 'DRIVER_LEAVE_REQUEST_FAILED',
            ], 500);
        }
    }

    /**
     * L'agent courant.
     *
     * Un profil `driver` sans ligne `drivers` est une incohérence de données, pas une
     * panne : le chemin Blade redirige avec « Profil Agent non trouvé », l'API répond
     * 409 — même choix qu'en 3a.
     */
    private function agent(Request $request): Driver
    {
        $driver = $request->user()?->driver;

        if (! $driver) {
            throw new ApiException(
                409,
                'DRIVER_PROFILE_MISSING',
                'Votre compte agent est incomplet. Contactez un administrateur.'
            );
        }

        return $driver;
    }
}
