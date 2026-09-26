<?php

namespace App\Domains\Identity\Presentation\Api\V1\Admin;

use App\Domains\Identity\Application\Actions\CreateRole;
use App\Domains\Identity\Application\Actions\DeleteRole;
use App\Domains\Identity\Application\Actions\FindRole;
use App\Domains\Identity\Application\Actions\ListPermissionCatalog;
use App\Domains\Identity\Application\Actions\ListRoles;
use App\Domains\Identity\Application\Actions\UpdateRole;
use App\Domains\Identity\Application\Data\AdminRoleDetailData;
use App\Domains\Identity\Application\Data\AdminRoleFormData;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Les rôles et le catalogue des permissions — ex-Admin\RoleController et
 * Admin\PermissionController. Le catalogue ne s'écrit plus : il n'a que sa lecture.
 *
 * Mêmes règles de try/catch que le reste de l'API v1.
 */
final class RoleController
{
    public function index(Request $request, ListRoles $list): JsonResponse
    {
        try {
            return response()->json($list());
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la liste des rôles',
                'La liste des rôles n\'a pas pu être chargée. Réessayez.', 'ADMIN_ROLES_FAILED');
        }
    }

    public function show(Request $request, string $roleId, FindRole $find): JsonResponse
    {
        try {
            return response()->json(AdminRoleDetailData::fromModel($find($roleId)));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la fiche du rôle',
                'La fiche de ce rôle n\'a pas pu être chargée. Réessayez.', 'ADMIN_ROLE_FAILED');
        }
    }

    public function permissions(Request $request, ListPermissionCatalog $list): JsonResponse
    {
        try {
            return response()->json($list());
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'le catalogue des permissions',
                'Le catalogue des permissions n\'a pas pu être chargé. Réessayez.', 'ADMIN_PERMISSIONS_FAILED');
        }
    }

    public function store(Request $request, AdminRoleFormData $data, CreateRole $create): JsonResponse
    {
        try {
            $role = $create($request->user(), $data);

            return response()->json(AdminRoleDetailData::fromModel($role->load('permissions')), 201);
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la création du rôle',
                'Ce rôle n\'a pas pu être créé.', 'ROLE_CREATE_FAILED');
        }
    }

    public function update(
        Request $request,
        string $roleId,
        FindRole $find,
        AdminRoleFormData $data,
        UpdateRole $update,
    ): JsonResponse {
        try {
            $role = $update($request->user(), $find($roleId), $data);

            return response()->json(AdminRoleDetailData::fromModel($role));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la modification du rôle',
                'Ce rôle n\'a pas pu être modifié.', 'ROLE_UPDATE_FAILED');
        }
    }

    public function destroy(Request $request, string $roleId, FindRole $find, DeleteRole $delete): Response|JsonResponse
    {
        try {
            $delete($request->user(), $find($roleId));

            return response()->noContent();
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la suppression du rôle',
                'Ce rôle n\'a pas pu être supprimé.', 'ROLE_DELETE_FAILED');
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
