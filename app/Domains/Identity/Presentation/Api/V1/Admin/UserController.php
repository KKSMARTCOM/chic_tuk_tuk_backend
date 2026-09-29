<?php

namespace App\Domains\Identity\Presentation\Api\V1\Admin;

use App\Domains\Audit\Application\ActivityJournal;
use App\Domains\Identity\Application\Actions\CreateAdminUser;
use App\Domains\Identity\Application\Actions\DeleteAdminUser;
use App\Domains\Identity\Application\Actions\FindAdminUser;
use App\Domains\Identity\Application\Actions\ListAdminUsers;
use App\Domains\Identity\Application\Actions\SetAdminUserStatus;
use App\Domains\Identity\Application\Actions\UpdateAdminUser;
use App\Domains\Identity\Application\Actions\UpdateAdminUserPassword;
use App\Domains\Identity\Application\Data\AdminUserFormData;
use App\Domains\Identity\Application\Data\AdminUserListItemData;
use App\Domains\Identity\Application\Data\SetAdminUserStatusData;
use App\Domains\Identity\Application\Data\UpdateAdminUserPasswordData;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Les comptes administrateurs — ex-Admin\UserController.
 *
 * Mêmes règles de try/catch que le reste de l'API v1 : ValidationException, ApiException
 * et ModelNotFoundException relancées en premier, `\Throwable` et non `\Exception`, le
 * message d'exception au journal seulement.
 */
final class UserController
{
    /** Chaque écriture est tracée APRÈS sa réussite : voir `ActivityJournal`. */
    public function __construct(private readonly ActivityJournal $journal) {}

    public function index(Request $request, ListAdminUsers $list): JsonResponse
    {
        try {
            return response()->json($list($request->query()));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la liste des administrateurs',
                'La liste des administrateurs n\'a pas pu être chargée. Réessayez.', 'ADMIN_USERS_FAILED');
        }
    }

    public function store(Request $request, AdminUserFormData $data, CreateAdminUser $create): JsonResponse
    {
        try {
            $user = $create($request->user(), $data);
            $this->journal->accountCreated($user);

            return response()->json(AdminUserListItemData::fromModel($user->load('roles')), 201);
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la création de l\'administrateur',
                'Cet administrateur n\'a pas pu être créé.', 'ADMIN_USER_CREATE_FAILED');
        }
    }

    public function update(
        Request $request,
        string $userId,
        FindAdminUser $find,
        AdminUserFormData $data,
        UpdateAdminUser $update,
    ): JsonResponse {
        try {
            $user = $update($request->user(), $find($userId), $data);
            $this->journal->accountUpdated($user);

            return response()->json(AdminUserListItemData::fromModel($user->load('roles')));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la modification de l\'administrateur',
                'Cet administrateur n\'a pas pu être modifié.', 'ADMIN_USER_UPDATE_FAILED');
        }
    }

    public function setStatus(
        Request $request,
        string $userId,
        FindAdminUser $find,
        SetAdminUserStatusData $data,
        SetAdminUserStatus $setStatus,
    ): JsonResponse {
        try {
            $target = $find($userId);
            $setStatus($request->user(), $target, $data->isActive);
            $this->journal->accountStatusChanged($target, $data->isActive);

            return response()->json(['message' => 'Statut du compte mis à jour avec succès.']);
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'le statut de l\'administrateur',
                'Le statut n\'a pas pu être mis à jour.', 'ADMIN_USER_STATUS_FAILED');
        }
    }

    public function updatePassword(
        Request $request,
        string $userId,
        FindAdminUser $find,
        UpdateAdminUserPasswordData $data,
        UpdateAdminUserPassword $update,
    ): JsonResponse {
        try {
            $target = $find($userId);
            $update($request->user(), $target, $data);
            $this->journal->accountPasswordSet($target);

            return response()->json(['message' => 'Mot de passe mis à jour avec succès.']);
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'le mot de passe de l\'administrateur',
                'Le mot de passe n\'a pas pu être mis à jour.', 'ADMIN_USER_PASSWORD_FAILED');
        }
    }

    public function destroy(Request $request, string $userId, FindAdminUser $find, DeleteAdminUser $delete): Response|JsonResponse
    {
        try {
            $target = $find($userId);
            $label = $this->journal->accountLabel($target);
            $delete($request->user(), $target);
            $this->journal->accountDeleted($target->id, $label);

            return response()->noContent();
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la suppression de l\'administrateur',
                'Cet administrateur n\'a pas pu être supprimé.', 'ADMIN_USER_DELETE_FAILED');
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
