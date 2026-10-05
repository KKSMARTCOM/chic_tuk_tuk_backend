<?php

namespace App\Domains\Fleet\Presentation\Api\V1\Admin;

use App\Domains\Audit\Application\ActivityJournal;
use App\Domains\Fleet\Application\Actions\CreateVehicleContract;
use App\Domains\Fleet\Application\Actions\DeleteVehicleContract;
use App\Domains\Fleet\Application\Actions\ListVehicleContracts;
use App\Domains\Fleet\Application\Actions\ShowVehicleContractDefaults;
use App\Domains\Fleet\Application\Actions\ShowVehicleContractDetail;
use App\Domains\Fleet\Application\Actions\UpdateVehicleContract;
use App\Domains\Fleet\Application\Data\CreateVehicleContractData;
use App\Domains\Fleet\Application\Data\UpdateVehicleContractData;
use App\Models\VehicleContract;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Les contrats propriétaire-véhicule, vus de l'administration — ex-Admin\VehicleContractController.
 *
 * Mêmes règles de try/catch que le reste de l'API v1. Les écritures renvoient la fiche
 * du contrat à jour.
 */
final class VehicleContractController
{
    /** Chaque écriture est tracée APRÈS sa réussite : voir `ActivityJournal`. */
    public function __construct(private readonly ActivityJournal $journal) {}

    public function index(Request $request, ListVehicleContracts $list): JsonResponse
    {
        try {
            return response()->json($list($request->query()));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la liste des contrats propriétaires',
                'La liste des contrats n\'a pas pu être chargée. Réessayez.', 'ADMIN_VEHICLE_CONTRACTS_FAILED');
        }
    }

    public function show(Request $request, string $contractId, ShowVehicleContractDetail $show): JsonResponse
    {
        try {
            return response()->json($show($contractId));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la fiche du contrat propriétaire',
                'La fiche de ce contrat n\'a pas pu être chargée. Réessayez.', 'ADMIN_VEHICLE_CONTRACT_FAILED');
        }
    }

    public function store(
        Request $request,
        CreateVehicleContractData $data,
        CreateVehicleContract $create,
        ShowVehicleContractDetail $show,
    ): JsonResponse {
        try {
            $contract = $create($data);
            $this->journal->vehicleContractCreated($contract->load('vehicle'));

            return response()->json($show($contract->id), 201);
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la création du contrat propriétaire',
                'Ce contrat n\'a pas pu être créé.', 'VEHICLE_CONTRACT_CREATE_FAILED');
        }
    }

    public function update(
        Request $request,
        string $contractId,
        UpdateVehicleContractData $data,
        UpdateVehicleContract $update,
        ShowVehicleContractDetail $show,
    ): JsonResponse {
        try {
            $contract = $update(VehicleContract::findOrFail($contractId), $data);
            $this->journal->vehicleContractUpdated($contract->load('vehicle'));

            return response()->json($show($contractId));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la modification du contrat propriétaire',
                'Ce contrat n\'a pas pu être modifié.', 'VEHICLE_CONTRACT_UPDATE_FAILED');
        }
    }

    public function destroy(Request $request, string $contractId, DeleteVehicleContract $delete): Response|JsonResponse
    {
        try {
            $contract = VehicleContract::with('vehicle')->findOrFail($contractId);
            $delete($contract);
            $this->journal->vehicleContractDeleted($contract->id, $contract->vehicle);

            return response()->noContent();
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la suppression du contrat propriétaire',
                'Ce contrat n\'a pas pu être supprimé.', 'VEHICLE_CONTRACT_DELETE_FAILED');
        }
    }

    public function defaults(Request $request, ShowVehicleContractDefaults $show): JsonResponse
    {
        try {
            return response()->json($show());
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'les valeurs par défaut des contrats propriétaires',
                'Les valeurs par défaut du contrat n\'ont pas pu être chargées. Réessayez.', 'VEHICLE_CONTRACT_DEFAULTS_FAILED');
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
