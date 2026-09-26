<?php

namespace App\Domains\Finance\Presentation\Api\V1\Admin;

use App\Domains\Finance\Application\Actions\CancelCommission;
use App\Domains\Finance\Application\Actions\ListCommissions;
use App\Domains\Finance\Application\Data\AdminCommissionData;
use App\Models\Commission;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** Les commissions, vues de l'administration — ex-Admin\CommissionController (P1). */
final class CommissionController
{
    public function index(Request $request, ListCommissions $list): JsonResponse
    {
        try {
            return response()->json($list([
                'driver_id' => $request->query('driver_id'),
                'search' => $request->query('search'),
            ]));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la liste des commissions',
                'La liste des commissions n\'a pas pu être chargée. Réessayez.', 'ADMIN_COMMISSIONS_FAILED');
        }
    }

    public function show(Request $request, string $commissionId): JsonResponse
    {
        try {
            $commission = Commission::with(['driver.user', 'booking'])->findOrFail($commissionId);

            return response()->json(AdminCommissionData::fromModel($commission));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la fiche de la commission',
                'Cette commission n\'a pas pu être chargée. Réessayez.', 'ADMIN_COMMISSION_FAILED');
        }
    }

    public function cancel(Request $request, string $commissionId, CancelCommission $cancel): JsonResponse
    {
        try {
            return response()->json($cancel($commissionId));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'l\'annulation de la commission',
                'Cette commission n\'a pas pu être annulée.', 'COMMISSION_CANCEL_FAILED');
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
