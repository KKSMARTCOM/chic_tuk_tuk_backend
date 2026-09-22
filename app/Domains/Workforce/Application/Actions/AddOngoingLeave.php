<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Models\Driver;
use App\Models\LeaveRequest;
use App\Services\VehicleService;
use App\Shared\Http\ApiException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Poser une pause directement, sans demande préalable — ex-Admin\LeaveController::addOngoingLeave().
 *
 * Le cas d'usage : un agent prévient par téléphone, ou un administrateur régularise une
 * absence en cours. La pause naît donc `ongoing`, sans passer par `pending`.
 *
 * ⚠️ `source` vaut `admin_instant`, ce qui la distingue d'une demande d'agent
 * (`driver_request`) et d'une saisie rétroactive (`admin_historical`). Les trois ne se
 * corrigent pas de la même façon, et `source` est ce qui permet de les séparer.
 *
 * ⚠️ L'agent n'est PAS notifié : c'est une saisie administrative sur une absence qu'il a
 * lui-même signalée. Le prévenir de ce qu'il vient d'annoncer serait du bruit.
 */
final class AddOngoingLeave
{
    public function __construct(private readonly VehicleService $vehicleService) {}

    public function __invoke(Driver $agent, string $dateDeDebut, int $joursDemandes, string $auteurId): LeaveRequest
    {
        if ($agent->hasOngoingLeave()) {
            throw new ApiException(
                409,
                'LEAVE_ALREADY_ONGOING',
                "L'agent a déjà une pause en cours. Terminez-la d'abord."
            );
        }

        $contrat = $agent->activeDriverContract;

        if (! $contrat) {
            throw new ApiException(409, 'LEAVE_NO_ACTIVE_CONTRACT', 'Aucun contrat actif pour cet agent.');
        }

        return DB::transaction(function () use ($agent, $contrat, $dateDeDebut, $joursDemandes, $auteurId) {
            $pause = LeaveRequest::create([
                'driver_id' => $agent->id,
                'driver_contract_id' => $contrat->id,
                'start_date' => $dateDeDebut,
                'requested_days' => $joursDemandes,
                'status' => 'ongoing',
                'source' => 'admin_instant',
                'created_by' => $auteurId,
            ]);

            $pauseVehicule = $this->vehicleService->createAutoAgentPause(
                $contrat->vehicle_id,
                $contrat->id,
                $dateDeDebut,
            );

            $pause->update(['vehicle_pause_id' => $pauseVehicule->id]);

            if (Carbon::parse($dateDeDebut)->lte(now()->startOfDay())) {
                $agent->update(['is_available' => false]);
            }

            return $pause->refresh();
        });
    }
}
