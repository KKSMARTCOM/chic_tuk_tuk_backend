<?php

namespace App\Domains\Fleet\Presentation\Api\V1\Admin;

use App\Domains\Fleet\Application\Actions\CancelVehiclePause;
use App\Domains\Fleet\Application\Actions\CreateVehicle;
use App\Domains\Fleet\Application\Actions\DeleteVehicle;
use App\Domains\Fleet\Application\Actions\EndVehiclePause;
use App\Domains\Fleet\Application\Actions\ListVehicles;
use App\Domains\Fleet\Application\Actions\PauseVehicle;
use App\Domains\Fleet\Application\Actions\SetVehicleStatus;
use App\Domains\Fleet\Application\Actions\ShowVehicleDetail;
use App\Domains\Fleet\Application\Actions\UpdateVehicle;
use App\Domains\Fleet\Application\Data\CreateVehiclePauseData;
use App\Domains\Fleet\Application\Data\EndVehiclePauseData;
use App\Domains\Fleet\Application\Data\SaveVehicleData;
use App\Domains\Fleet\Application\Data\SetVehicleStatusData;
use App\Models\Vehicle;
use App\Models\VehiclePause;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Les véhicules et leurs pauses, vus de l'administration — ex-Admin\VehicleController.
 *
 * Mêmes règles de try/catch que le reste de l'API v1. Les écritures qui touchent un
 * véhicule renvoient sa fiche à jour, que l'écran affiche telle quelle.
 */
final class VehicleController
{
    public function index(Request $request, ListVehicles $list): JsonResponse
    {
        try {
            return response()->json($list([
                'search' => $request->query('search'),
                'is_active' => $request->query('is_active'),
                'owner_id' => $request->query('owner_id'),
            ]));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la liste des véhicules',
                'La liste des véhicules n\'a pas pu être chargée. Réessayez.', 'ADMIN_VEHICLES_FAILED');
        }
    }

    public function show(Request $request, string $vehicleId, ShowVehicleDetail $show): JsonResponse
    {
        try {
            return response()->json($show($vehicleId));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la fiche du véhicule',
                'La fiche de ce véhicule n\'a pas pu être chargée. Réessayez.', 'ADMIN_VEHICLE_FAILED');
        }
    }

    public function store(Request $request, SaveVehicleData $data, CreateVehicle $create, ShowVehicleDetail $show): JsonResponse
    {
        try {
            $vehicle = $create($data);

            return response()->json($show($vehicle->id), 201);
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la création du véhicule',
                'Ce véhicule n\'a pas pu être créé.', 'VEHICLE_CREATE_FAILED');
        }
    }

    public function update(
        Request $request,
        string $vehicleId,
        SaveVehicleData $data,
        UpdateVehicle $update,
        ShowVehicleDetail $show,
    ): JsonResponse {
        try {
            $update(Vehicle::findOrFail($vehicleId), $data);

            return response()->json($show($vehicleId));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la modification du véhicule',
                'Ce véhicule n\'a pas pu être modifié.', 'VEHICLE_UPDATE_FAILED');
        }
    }

    public function setStatus(
        Request $request,
        string $vehicleId,
        SetVehicleStatusData $data,
        SetVehicleStatus $setStatus,
    ): JsonResponse {
        try {
            $setStatus(Vehicle::findOrFail($vehicleId), $data->isActive);

            return response()->json(['message' => 'Statut du véhicule mis à jour avec succès.']);
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'le statut du véhicule',
                'Le statut n\'a pas pu être mis à jour.', 'VEHICLE_STATUS_FAILED');
        }
    }

    public function destroy(Request $request, string $vehicleId, DeleteVehicle $delete): Response|JsonResponse
    {
        try {
            $delete(Vehicle::findOrFail($vehicleId));

            return response()->noContent();
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la suppression du véhicule',
                'Ce véhicule n\'a pas pu être supprimé.', 'VEHICLE_DELETE_FAILED');
        }
    }

    public function storePause(
        Request $request,
        string $vehicleId,
        CreateVehiclePauseData $data,
        PauseVehicle $pause,
        ShowVehicleDetail $show,
    ): JsonResponse {
        try {
            $pause(Vehicle::findOrFail($vehicleId), $data);

            return response()->json($show($vehicleId), 201);
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la mise en pause du véhicule',
                'La pause n\'a pas pu être enregistrée.', 'VEHICLE_PAUSE_FAILED');
        }
    }

    public function endPause(
        Request $request,
        string $pauseId,
        EndVehiclePauseData $data,
        EndVehiclePause $end,
        ShowVehicleDetail $show,
    ): JsonResponse {
        try {
            $pause = $end(VehiclePause::findOrFail($pauseId), $data->endDate);

            return response()->json($show($pause->vehicle_id));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la fin de la pause véhicule',
                'La pause n\'a pas pu être terminée.', 'VEHICLE_PAUSE_END_FAILED');
        }
    }

    public function cancelPause(Request $request, string $pauseId, CancelVehiclePause $cancel): Response|JsonResponse
    {
        try {
            $cancel(VehiclePause::findOrFail($pauseId));

            return response()->noContent();
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'l\'annulation de la pause véhicule',
                'La pause n\'a pas pu être annulée.', 'VEHICLE_PAUSE_CANCEL_FAILED');
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
