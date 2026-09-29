<?php

namespace App\Domains\Workforce\Presentation\Api\V1\Admin;

use App\Domains\Audit\Application\ActivityJournal;
use App\Domains\Workforce\Application\Actions\CreateDriver;
use App\Domains\Workforce\Application\Actions\DeleteDriver;
use App\Domains\Workforce\Application\Actions\ListDrivers;
use App\Domains\Workforce\Application\Actions\ListOwnersForNewContract;
use App\Domains\Workforce\Application\Actions\ListOwnersForRenewal;
use App\Domains\Workforce\Application\Actions\ShowDriverDetail;
use App\Domains\Workforce\Application\Actions\ToggleDriverAvailability;
use App\Domains\Workforce\Application\Actions\ToggleDriverStatus;
use App\Domains\Workforce\Application\Actions\UpdateDriver;
use App\Domains\Workforce\Application\Actions\UpdateDriverPassword;
use App\Domains\Workforce\Application\Data\CreateDriverData;
use App\Domains\Workforce\Application\Data\ToggleDriverAvailabilityData;
use App\Domains\Workforce\Application\Data\ToggleDriverStatusData;
use App\Domains\Workforce\Application\Data\UpdateDriverData;
use App\Domains\Workforce\Application\Data\UpdateDriverPasswordData;
use App\Models\Driver;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Les agents, vus de l'administration — ex-Admin\DriverController.
 *
 * Mêmes règles de try/catch que le reste de l'API v1 : ValidationException, ApiException
 * et ModelNotFoundException relancées en premier, `\Throwable` et non `\Exception`, le
 * message d'exception au journal seulement.
 */
final class DriverController
{
    /** Chaque écriture est tracée APRÈS sa réussite : voir `ActivityJournal`. */
    public function __construct(private readonly ActivityJournal $journal) {}

    public function index(Request $request, ListDrivers $list): JsonResponse
    {
        try {
            $result = $list($request->query());

            return response()->json($result);
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->echec($e, $request, 'la liste des agents',
                'La liste des agents n\'a pas pu être chargée. Réessayez.', 'ADMIN_DRIVERS_FAILED');
        }
    }

    public function show(Request $request, string $driverId, ShowDriverDetail $show): JsonResponse
    {
        try {
            return response()->json($show($driverId));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->echec($e, $request, 'le dossier de l\'agent',
                'Le dossier de cet agent n\'a pas pu être chargé. Réessayez.', 'ADMIN_DRIVER_FAILED');
        }
    }

    public function ownersForNewContract(Request $request, ListOwnersForNewContract $list): JsonResponse
    {
        try {
            return response()->json($list());
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->echec($e, $request, 'les propriétaires proposés',
                'La liste des propriétaires n\'a pas pu être chargée. Réessayez.', 'ADMIN_OWNERS_FAILED');
        }
    }

    public function ownersForRenewal(Request $request, ListOwnersForRenewal $list): JsonResponse
    {
        try {
            return response()->json($list());
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->echec($e, $request, 'les propriétaires proposés pour reconduction',
                'La liste des propriétaires n\'a pas pu être chargée. Réessayez.', 'ADMIN_OWNERS_FAILED');
        }
    }

    public function store(Request $request, CreateDriverData $data, CreateDriver $create, ShowDriverDetail $show): JsonResponse
    {
        try {
            $user = $create($data);
            $this->journal->accountCreated($user, $user->driver);

            return response()->json($show($user->driver->id), 201);
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->echec($e, $request, 'la création de l\'agent',
                'Cet agent n\'a pas pu être créé.', 'DRIVER_CREATE_FAILED');
        }
    }

    public function update(
        Request $request,
        string $driverId,
        UpdateDriverData $data,
        UpdateDriver $update,
        ShowDriverDetail $show,
    ): JsonResponse {
        try {
            $driver = Driver::findOrFail($driverId);
            $update($driver, $data);
            $driver->refresh()->load('user');
            if ($driver->user) {
                $this->journal->accountUpdated($driver->user, $driver);
            }

            return response()->json($show($driverId));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->echec($e, $request, 'la modification de l\'agent',
                'Cet agent n\'a pas pu être modifié.', 'DRIVER_UPDATE_FAILED');
        }
    }

    public function toggleAvailability(
        Request $request,
        string $driverId,
        ToggleDriverAvailabilityData $data,
        ToggleDriverAvailability $toggle,
    ): JsonResponse {
        try {
            $driver = Driver::with('user')->findOrFail($driverId);
            $toggle($driver, $data->isAvailable);
            $this->journal->driverAvailabilityChanged($driver, $data->isAvailable);

            return response()->json(['message' => 'Disponibilité mise à jour avec succès.']);
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->echec($e, $request, 'la disponibilité de l\'agent',
                'La disponibilité n\'a pas pu être mise à jour.', 'DRIVER_AVAILABILITY_FAILED');
        }
    }

    public function toggleStatus(
        Request $request,
        string $driverId,
        ToggleDriverStatusData $data,
        ToggleDriverStatus $toggle,
    ): JsonResponse {
        try {
            $driver = Driver::with('user')->findOrFail($driverId);
            $toggle($driver, $data->isActive);
            if ($driver->user) {
                $this->journal->accountStatusChanged($driver->user, $data->isActive, $driver);
            }

            return response()->json(['message' => 'Statut du compte mis à jour avec succès.']);
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->echec($e, $request, 'le statut de l\'agent',
                'Le statut n\'a pas pu être mis à jour.', 'DRIVER_STATUS_FAILED');
        }
    }

    public function updatePassword(
        Request $request,
        string $driverId,
        UpdateDriverPasswordData $data,
        UpdateDriverPassword $update,
    ): JsonResponse {
        try {
            $driver = Driver::with('user')->findOrFail($driverId);
            $update($driver, $data);
            if ($driver->user) {
                $this->journal->accountPasswordSet($driver->user, $driver);
            }

            return response()->json(['message' => 'Mot de passe mis à jour avec succès.']);
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->echec($e, $request, 'le mot de passe de l\'agent',
                'Le mot de passe n\'a pas pu être mis à jour.', 'DRIVER_PASSWORD_FAILED');
        }
    }

    public function destroy(Request $request, string $driverId, DeleteDriver $delete): Response
    {
        try {
            $driver = Driver::with('user')->findOrFail($driverId);
            $label = $driver->user ? $this->journal->accountLabel($driver->user) : 'd\'un agent';
            $delete($driver);
            $this->journal->accountDeleted($driver->id, $label);

            return response()->noContent();
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->echec($e, $request, 'la suppression de l\'agent',
                'Cet agent n\'a pas pu être supprimé.', 'DRIVER_DELETE_FAILED');
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
