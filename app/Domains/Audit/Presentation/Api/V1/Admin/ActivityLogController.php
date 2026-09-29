<?php

namespace App\Domains\Audit\Presentation\Api\V1\Admin;

use App\Domains\Audit\Application\Actions\ListActivityLog;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** Le journal d'activité, en lecture seule. Mêmes règles de try/catch que l'API v1. */
final class ActivityLogController
{
    public function index(Request $request, ListActivityLog $list): JsonResponse
    {
        try {
            return response()->json($list($request->query()));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Erreur lors de la lecture du journal d\'activité : '.$e->getMessage(), [
                'exception' => $e,
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'Le journal d\'activité n\'a pas pu être chargé. Réessayez.',
                'code' => 'ACTIVITY_LOG_READ_FAILED',
            ], 500);
        }
    }
}
