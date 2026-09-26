<?php

namespace App\Domains\Workforce\Presentation\Api\V1\Admin;

use App\Domains\Workforce\Application\Actions\DeleteDriverContract;
use App\Domains\Workforce\Application\Actions\EndDriverContract;
use App\Domains\Workforce\Application\Actions\ListAssignableVehicles;
use App\Domains\Workforce\Application\Actions\ListDriverContracts;
use App\Domains\Workforce\Application\Actions\ShowDriverContractDetail;
use App\Domains\Workforce\Application\Actions\UpdateDriverContract;
use App\Domains\Workforce\Application\Data\EndDriverContractData;
use App\Domains\Workforce\Application\Data\UpdateDriverContractData;
use App\Models\DriverContract;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Les contrats agents, vus de l'administration — ex-Admin\DriverContractController (F4).
 *
 * Pas de création ici : un contrat agent naît avec l'agent, depuis sa création ou son
 * édition. Les écritures renvoient la fiche du contrat à jour.
 */
final class DriverContractController
{
    public function index(Request $request, ListDriverContracts $list): JsonResponse
    {
        try {
            return response()->json($list());
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la liste des contrats agents',
                'La liste des contrats n\'a pas pu être chargée. Réessayez.', 'ADMIN_DRIVER_CONTRACTS_FAILED');
        }
    }

    public function assignableVehicles(Request $request, ListAssignableVehicles $list): JsonResponse
    {
        try {
            return response()->json($list());
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la liste des véhicules assignables',
                'Les véhicules n\'ont pas pu être chargés. Réessayez.', 'ASSIGNABLE_VEHICLES_FAILED');
        }
    }

    public function show(Request $request, string $contractId, ShowDriverContractDetail $show): JsonResponse
    {
        try {
            return response()->json($show($contractId));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la fiche du contrat agent',
                'La fiche de ce contrat n\'a pas pu être chargée. Réessayez.', 'ADMIN_DRIVER_CONTRACT_FAILED');
        }
    }

    public function update(
        Request $request,
        string $contractId,
        UpdateDriverContractData $data,
        UpdateDriverContract $update,
        ShowDriverContractDetail $show,
    ): JsonResponse {
        try {
            $update(DriverContract::findOrFail($contractId), $data);

            return response()->json($show($contractId));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la modification du contrat agent',
                'Ce contrat n\'a pas pu être modifié.', 'DRIVER_CONTRACT_UPDATE_FAILED');
        }
    }

    public function end(
        Request $request,
        string $contractId,
        EndDriverContractData $data,
        EndDriverContract $end,
        ShowDriverContractDetail $show,
    ): JsonResponse {
        try {
            $end(DriverContract::findOrFail($contractId), $data);

            return response()->json($show($contractId));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la clôture du contrat agent',
                'Ce contrat n\'a pas pu être terminé.', 'DRIVER_CONTRACT_END_FAILED');
        }
    }

    public function destroy(Request $request, string $contractId, DeleteDriverContract $delete): Response|JsonResponse
    {
        try {
            $delete(DriverContract::findOrFail($contractId));

            return response()->noContent();
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la suppression du contrat agent',
                'Ce contrat n\'a pas pu être supprimé.', 'DRIVER_CONTRACT_DELETE_FAILED');
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
