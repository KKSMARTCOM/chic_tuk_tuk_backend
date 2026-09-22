<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Domains\Notification\Application\Notifier;
use App\Models\LeaveRequest;
use App\Services\VehicleService;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Approuver une demande de pause — ex-Admin\LeaveController::approveRequest().
 *
 * ⚠️ Approuver ne fait pas que changer un statut : la pause passe directement en
 * `ongoing` — le statut `approved` a été retiré de la base en août —, une pause VÉHICULE
 * est créée pour immobiliser le tricycle, et l'agent est rendu indisponible si la pause
 * commence aujourd'hui ou avant. Oublier l'un des trois laisse le parc dans un état
 * incohérent.
 *
 * Les deux refus étaient des `redirect()->back()->with('error')`, invisibles pour une
 * API — ils ressortiraient en 500. Ils portent désormais leur statut et leur code, avec
 * les messages repris mot pour mot pour que le chemin Blade affiche les mêmes flash.
 */
final class ApproveLeaveRequest
{
    public function __construct(
        private readonly VehicleService $vehicleService,
        private readonly Notifier $notifier,
    ) {}

    public function __invoke(LeaveRequest $demande): LeaveRequest
    {
        $agent = $demande->driver;

        if (! $agent?->activeDriverContract) {
            throw new ApiException(
                409,
                'LEAVE_NO_ACTIVE_CONTRACT',
                "Cet agent n'a pas de contrat actif. Impossible d'approuver la pause."
            );
        }

        if ($agent->hasOngoingLeave()) {
            throw new ApiException(
                409,
                'LEAVE_ALREADY_ONGOING',
                "L'agent a déjà une pause en cours."
            );
        }

        $contrat = $agent->activeDriverContract;

        $approuvee = DB::transaction(function () use ($demande, $agent, $contrat) {
            $demande->update(['status' => 'ongoing', 'rejection_reason' => null]);

            // La pause véhicule : le tricycle ne roule pas pendant que son agent est en
            // pause, et son propriétaire doit le savoir.
            $pause = $this->vehicleService->createAutoAgentPause(
                $contrat->vehicle_id,
                $contrat->id,
                $demande->start_date->toDateString(),
            );

            $demande->update(['vehicle_pause_id' => $pause->id]);

            // Une pause qui commence aujourd'hui ou avant prend effet tout de suite ;
            // une pause future laisse l'agent disponible d'ici là.
            if ($demande->start_date->lte(now()->startOfDay())) {
                $agent->update(['is_available' => false]);
            }

            return $demande->refresh();
        });

        // ⚠️ APRÈS la transaction : un push envoyé dedans partirait pour une opération
        // qu'un `rollback` annulerait ensuite.
        $this->notifier->leaveApproved($approuvee);

        return $approuvee;
    }
}
