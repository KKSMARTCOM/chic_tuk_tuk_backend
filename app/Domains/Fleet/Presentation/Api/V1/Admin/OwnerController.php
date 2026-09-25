<?php

namespace App\Domains\Fleet\Presentation\Api\V1\Admin;

use App\Domains\Fleet\Application\Actions\CreateOwner;
use App\Domains\Fleet\Application\Actions\DeleteOwner;
use App\Domains\Fleet\Application\Actions\FindOwner;
use App\Domains\Fleet\Application\Actions\ListAvailableVehicles;
use App\Domains\Fleet\Application\Actions\ListOwners;
use App\Domains\Fleet\Application\Actions\SetOwnerStatus;
use App\Domains\Fleet\Application\Actions\ShowOwnerDetail;
use App\Domains\Fleet\Application\Actions\UpdateOwner;
use App\Domains\Fleet\Application\Actions\UpdateOwnerPassword;
use App\Domains\Fleet\Application\Data\CreateOwnerData;
use App\Domains\Fleet\Application\Data\SetOwnerStatusData;
use App\Domains\Fleet\Application\Data\UpdateOwnerData;
use App\Domains\Fleet\Application\Data\UpdateOwnerPasswordData;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Les propriétaires, vus de l'administration — ex-Admin\OwnerController.
 *
 * Mêmes règles de try/catch que le reste de l'API v1 : ValidationException, ApiException
 * et ModelNotFoundException relancées en premier, `\Throwable` et non `\Exception`, le
 * message d'exception au journal seulement.
 */
final class OwnerController
{
    public function index(Request $request, ListOwners $list): JsonResponse
    {
        try {
            return response()->json($list([
                'search' => $request->query('search'),
                'is_active' => $request->query('is_active'),
            ]));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la liste des propriétaires',
                'La liste des propriétaires n\'a pas pu être chargée. Réessayez.', 'ADMIN_OWNERS_FAILED');
        }
    }

    public function show(Request $request, string $ownerId, ShowOwnerDetail $show): JsonResponse
    {
        try {
            return response()->json($show($ownerId));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la fiche du propriétaire',
                'La fiche de ce propriétaire n\'a pas pu être chargée. Réessayez.', 'ADMIN_OWNER_FAILED');
        }
    }

    public function availableVehicles(Request $request, ListAvailableVehicles $list): JsonResponse
    {
        try {
            return response()->json($list($request->query('owner_id')));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'les véhicules disponibles',
                'La liste des véhicules n\'a pas pu être chargée. Réessayez.', 'ADMIN_AVAILABLE_VEHICLES_FAILED');
        }
    }

    public function store(Request $request, CreateOwnerData $data, CreateOwner $create, ShowOwnerDetail $show): JsonResponse
    {
        try {
            $owner = $create($data);

            return response()->json($show($owner->id), 201);
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la création du propriétaire',
                'Ce propriétaire n\'a pas pu être créé.', 'OWNER_CREATE_FAILED');
        }
    }

    public function update(
        Request $request,
        string $ownerId,
        FindOwner $findOwner,
        UpdateOwnerData $data,
        UpdateOwner $update,
        ShowOwnerDetail $show,
    ): JsonResponse {
        try {
            $update($findOwner($ownerId), $data);

            return response()->json($show($ownerId));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la modification du propriétaire',
                'Ce propriétaire n\'a pas pu être modifié.', 'OWNER_UPDATE_FAILED');
        }
    }

    public function setStatus(
        Request $request,
        string $ownerId,
        FindOwner $findOwner,
        SetOwnerStatusData $data,
        SetOwnerStatus $setStatus,
    ): JsonResponse {
        try {
            $setStatus($findOwner($ownerId), $data->isActive);

            return response()->json(['message' => 'Statut du compte mis à jour avec succès.']);
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'le statut du propriétaire',
                'Le statut n\'a pas pu être mis à jour.', 'OWNER_STATUS_FAILED');
        }
    }

    public function updatePassword(
        Request $request,
        string $ownerId,
        FindOwner $findOwner,
        UpdateOwnerPasswordData $data,
        UpdateOwnerPassword $update,
    ): JsonResponse {
        try {
            $update($findOwner($ownerId), $data);

            return response()->json(['message' => 'Mot de passe mis à jour avec succès.']);
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'le mot de passe du propriétaire',
                'Le mot de passe n\'a pas pu être mis à jour.', 'OWNER_PASSWORD_FAILED');
        }
    }

    public function destroy(Request $request, string $ownerId, FindOwner $findOwner, DeleteOwner $delete): Response|JsonResponse
    {
        try {
            $delete($findOwner($ownerId));

            return response()->noContent();
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la suppression du propriétaire',
                'Ce propriétaire n\'a pas pu être supprimé.', 'OWNER_DELETE_FAILED');
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
