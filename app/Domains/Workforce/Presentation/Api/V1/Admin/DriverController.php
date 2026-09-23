<?php

namespace App\Domains\Workforce\Presentation\Api\V1\Admin;

use App\Domains\Workforce\Application\Actions\ListDrivers;
use App\Domains\Workforce\Application\Actions\ShowDriverDetail;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Les agents, vus de l'administration, en LECTURE SEULE — ex-Admin\DriverController
 * (index et show uniquement). Création, édition, mot de passe, disponibilité, statut et
 * suppression restent à faire dans un sous-lot suivant.
 *
 * Mêmes règles de try/catch que le reste de l'API v1 : ValidationException, ApiException
 * et ModelNotFoundException relancées en premier, `\Throwable` et non `\Exception`, le
 * message d'exception au journal seulement.
 */
final class DriverController
{
    public function index(Request $request, ListDrivers $list): JsonResponse
    {
        try {
            $result = $list([
                'search' => $request->query('search'),
                'is_active' => $request->query('is_active'),
                'is_available' => $request->query('is_available'),
            ]);

            return response()->json($result);
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->echec($e, $request, 'la liste des agents',
                'La liste des agents n\'a pas pu être chargée. Réessayez.', 'ADMIN_DRIVERS_FAILED');
        }
    }

    public function show(Request $request, string $driverId, ShowDriverDetail $show): JsonResponse
    {
        try {
            return response()->json($show($driverId));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->echec($e, $request, 'le dossier de l\'agent',
                'Le dossier de cet agent n\'a pas pu être chargé. Réessayez.', 'ADMIN_DRIVER_FAILED');
        }
    }

    private function echec(\Throwable $e, Request $request, string $quoi, string $message, string $code): JsonResponse
    {
        Log::error("Erreur lors de {$quoi} : ".$e->getMessage(), [
            'exception' => $e,
            'user_id' => $request->user()?->id,
        ]);

        return response()->json(['message' => $message, 'code' => $code], 500);
    }
}
