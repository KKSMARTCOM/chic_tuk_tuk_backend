<?php

namespace App\Domains\Workforce\Presentation\Api\V1\Admin;

use App\Domains\Workforce\Application\Actions\ListDriversForLeaves;
use App\Domains\Workforce\Application\Data\AdminDriverLeaveDetailData;
use App\Domains\Workforce\Application\Data\AdminDriverLeaveSummaryData;
use App\Domains\Workforce\Application\Data\AdminLeaveRequestData;
use App\Models\Driver;
use App\Models\LeaveRequest;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Les congés, vus de l'administration : la liste des agents, le dossier de l'un d'eux,
 * et la file des demandes à traiter.
 *
 * Mêmes règles de try/catch que les espaces agent et propriétaire : ValidationException,
 * ApiException et ModelNotFoundException relancées EN PREMIER, `\Throwable` et non
 * `\Exception`, et le message d'exception au journal seulement.
 */
final class LeaveController
{
    public function index(Request $request, ListDriversForLeaves $lister): JsonResponse
    {
        try {
            $agents = $lister([
                'search' => $request->query('search'),
                'contract' => $request->query('contract'),
                'available' => $request->query('available'),
                'pending' => $request->query('pending'),
                'status' => $request->query('status'),
            ]);

            return response()->json(
                $agents->map(fn (Driver $d) => AdminDriverLeaveSummaryData::fromModel($d))->all()
            );
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->echec($e, $request, 'la liste des congés',
                'La liste des congés n\'a pas pu être chargée. Réessayez.', 'ADMIN_LEAVES_FAILED');
        }
    }

    public function show(Request $request, string $driverId): JsonResponse
    {
        try {
            $agent = Driver::with(['user', 'activeDriverContract'])->findOrFail($driverId);

            return response()->json(AdminDriverLeaveDetailData::fromModel($agent));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->echec($e, $request, 'le dossier de congés d\'un agent',
                'Ce dossier n\'a pas pu être chargé. Réessayez.', 'ADMIN_LEAVE_DETAIL_FAILED');
        }
    }

    public function requests(Request $request): JsonResponse
    {
        try {
            $demandes = LeaveRequest::with(['driver.user', 'driver.activeDriverContract'])
                ->where('status', 'pending')
                ->orderByDesc('created_at')
                ->get();

            return response()->json(
                $demandes->map(fn (LeaveRequest $d) => AdminLeaveRequestData::fromModel($d))->all()
            );
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->echec($e, $request, 'la file des demandes de pause',
                'Les demandes n\'ont pas pu être chargées. Réessayez.', 'ADMIN_LEAVE_REQUESTS_FAILED');
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
