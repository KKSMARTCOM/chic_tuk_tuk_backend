<?php

namespace App\Domains\Workforce\Presentation\Api\V1\Admin;

use App\Domains\Workforce\Application\Actions\AddHistoricalLeave;
use App\Domains\Workforce\Application\Actions\AddOngoingLeave;
use App\Domains\Workforce\Application\Actions\ApproveLeaveRequest;
use App\Domains\Workforce\Application\Actions\DeleteLeave;
use App\Domains\Workforce\Application\Actions\EndLeave;
use App\Domains\Workforce\Application\Actions\ListDriversForLeaves;
use App\Domains\Workforce\Application\Actions\RejectLeaveRequest;
use App\Domains\Workforce\Application\Actions\UpdateHistoricalLeave;
use App\Domains\Workforce\Application\Actions\UpdateOngoingLeave;
use App\Domains\Workforce\Application\Data\AdminDriverLeaveDetailData;
use App\Domains\Workforce\Application\Data\AdminDriverLeaveSummaryData;
use App\Domains\Workforce\Application\Data\AdminLeaveRequestData;
use App\Domains\Workforce\Application\Data\EndLeaveData;
use App\Domains\Workforce\Application\Data\LeavePeriodData;
use App\Domains\Workforce\Application\Data\LeaveRequestData;
use App\Domains\Workforce\Application\Data\RejectLeaveData;
use App\Models\Driver;
use App\Models\LeaveRequest;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Les pauses, vus de l'administration : la liste des agents, le dossier de l'un d'eux,
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
            return $this->echec($e, $request, 'la liste des pauses',
                'La liste des pauses n\'a pas pu être chargée. Réessayez.', 'ADMIN_LEAVES_FAILED');
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
            return $this->echec($e, $request, 'le dossier de pauses d\'un agent',
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

    // ----- Les écritures -----------------------------------------------------

    public function approve(Request $request, string $id, ApproveLeaveRequest $approuver): JsonResponse
    {
        try {
            $demande = LeaveRequest::findOrFail($id);

            return response()->json(LeaveRequestData::fromModel($approuver($demande)));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->echec($e, $request, "l'approbation d'une pause",
                "Cette demande n'a pas pu être approuvée.", 'LEAVE_APPROVE_FAILED');
        }
    }

    public function reject(Request $request, string $id, RejectLeaveData $data, RejectLeaveRequest $refuser): JsonResponse
    {
        try {
            $demande = LeaveRequest::findOrFail($id);

            return response()->json(LeaveRequestData::fromModel($refuser($demande, $data->rejectionReason)));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->echec($e, $request, "le refus d'une pause",
                "Cette demande n'a pas pu être refusée.", 'LEAVE_REJECT_FAILED');
        }
    }

    public function end(Request $request, string $id, EndLeaveData $data, EndLeave $cloturer): JsonResponse
    {
        try {
            $pause = LeaveRequest::findOrFail($id);

            return response()->json(LeaveRequestData::fromModel($cloturer($pause, $data->endDate)));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->echec($e, $request, "la clôture d'une pause",
                "Cette pause n'a pas pu être clôturée.", 'LEAVE_END_FAILED');
        }
    }

    public function storeOngoing(Request $request, string $driverId, LeavePeriodData $data, AddOngoingLeave $poser): JsonResponse
    {
        try {
            $agent = Driver::findOrFail($driverId);
            $pause = $poser($agent, $data->startDate, $data->requestedDays, (string) $request->user()->id);

            return response()->json(LeaveRequestData::fromModel($pause), 201);
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->echec($e, $request, "l'ajout d'une pause en cours",
                "Cette pause n'a pas pu être ajoutée.", 'LEAVE_ADD_ONGOING_FAILED');
        }
    }

    public function storeHistorical(Request $request, string $driverId, LeavePeriodData $data, AddHistoricalLeave $saisir): JsonResponse
    {
        try {
            $agent = Driver::findOrFail($driverId);
            $pause = $saisir($agent, $data->startDate, $data->requestedDays, (string) $request->user()->id);

            return response()->json(LeaveRequestData::fromModel($pause), 201);
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->echec($e, $request, "la saisie d'une pause historique",
                "Cette pause n'a pas pu être saisie.", 'LEAVE_ADD_HISTORICAL_FAILED');
        }
    }

    public function updateHistorical(Request $request, string $id, LeavePeriodData $data, UpdateHistoricalLeave $corriger): JsonResponse
    {
        try {
            $pause = LeaveRequest::findOrFail($id);

            return response()->json(
                LeaveRequestData::fromModel($corriger($pause, $data->startDate, $data->requestedDays))
            );
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->echec($e, $request, "la correction d'une pause historique",
                "Cette pause n'a pas pu être corrigée.", 'LEAVE_UPDATE_HISTORICAL_FAILED');
        }
    }

    public function updateOngoing(Request $request, string $id, LeavePeriodData $data, UpdateOngoingLeave $corriger): JsonResponse
    {
        try {
            $pause = LeaveRequest::findOrFail($id);

            return response()->json(
                LeaveRequestData::fromModel($corriger($pause, $data->startDate, $data->requestedDays))
            );
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->echec($e, $request, "la correction d'une pause en cours",
                "Cette pause n'a pas pu être corrigée.", 'LEAVE_UPDATE_ONGOING_FAILED');
        }
    }

    public function destroy(Request $request, string $id, DeleteLeave $supprimer): Response|JsonResponse
    {
        try {
            $supprimer(LeaveRequest::findOrFail($id));

            return response()->noContent();
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->echec($e, $request, "la suppression d'une pause historique",
                "Cette pause n'a pas pu être supprimée.", 'LEAVE_DELETE_FAILED');
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
