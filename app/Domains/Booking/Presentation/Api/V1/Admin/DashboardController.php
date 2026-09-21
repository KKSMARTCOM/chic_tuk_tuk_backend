<?php

namespace App\Domains\Booking\Presentation\Api\V1\Admin;

use App\Domains\Booking\Application\Actions\BuildAdminDashboard;
use App\Domains\Booking\Application\Data\AdminDashboardData;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Le tableau de bord de l'administration.
 *
 * Mêmes règles de try/catch que les espaces agent et propriétaire : ValidationException,
 * ApiException et ModelNotFoundException relancées EN PREMIER, `\Throwable` et non
 * `\Exception`, et le message d'exception au journal seulement.
 */
final class DashboardController
{
    public function __invoke(Request $request, BuildAdminDashboard $construire): JsonResponse
    {
        try {
            return response()->json(AdminDashboardData::fromStats($construire()));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Erreur lors de la lecture du tableau de bord admin : '.$e->getMessage(), [
                'exception' => $e,
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'Le tableau de bord n\'a pas pu être chargé. Réessayez.',
                'code' => 'ADMIN_DASHBOARD_FAILED',
            ], 500);
        }
    }
}
