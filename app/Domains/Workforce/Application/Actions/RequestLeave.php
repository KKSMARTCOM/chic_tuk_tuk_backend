<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Domains\Notification\Application\Notifier;
use App\Models\Driver;
use App\Models\LeaveRequest;
use App\Shared\Http\ApiException;
use Carbon\Carbon;

/**
 * Déposer une demande de pause — ex-DriverLeaveController::store().
 *
 * Déplacée sans changer l'ordre des contrôles : il compte, parce qu'un agent sans
 * contrat actif ne doit pas d'abord s'entendre dire que sa date est trop proche.
 *
 * ⚠️ Les refus étaient des `redirect()->back()->with('error', …)`, donc invisibles pour
 * une API : ils ressortiraient en 500. Ils portent désormais leur statut et leur code,
 * comme les cinq écritures de courses du sous-lot 3a. Les messages sont repris mot pour
 * mot, pour que le chemin Blade affiche les mêmes flash.
 */
final class RequestLeave
{
    public function __construct(private readonly Notifier $notifier) {}

    public function __invoke(Driver $driver, string $startDate, int $requestedDays): LeaveRequest
    {
        $contract = $driver->activeDriverContract;

        if (! $contract) {
            throw new ApiException(
                409,
                'LEAVE_NO_ACTIVE_CONTRACT',
                'Vous devez être sous contrat actif pour demander une pause.'
            );
        }

        $start = Carbon::parse($startDate)->startOfDay();

        if ($start->lt(Carbon::parse($contract->start_date)->startOfDay())) {
            throw new ApiException(
                409,
                'LEAVE_BEFORE_CONTRACT_START',
                'La date de début de la pause ne peut pas être antérieure à la date de début du contrat ('
                .Carbon::parse($contract->start_date)->format('d/m/Y').').'
            );
        }

        // ⚠️ `pending` ET `ongoing` : une demande REFUSÉE ne bloque pas une nouvelle.
        if ($driver->leaveRequests()->whereIn('status', ['pending', 'ongoing'])->exists()) {
            throw new ApiException(
                409,
                'LEAVE_ALREADY_PENDING',
                'Vous avez déjà une demande en attente ou une pause en cours.'
            );
        }

        $demande = LeaveRequest::create([
            'driver_id' => $driver->id,
            'driver_contract_id' => $contract->id,
            'start_date' => $startDate,
            'requested_days' => $requestedDays,
            'status' => 'pending',
            // Distingue une demande d'agent d'une pause posée par un administrateur.
            'source' => 'driver_request',
        ]);

        // Les administrateurs ont la demande à traiter : sans notification, elle attend
        // que l'un d'eux pense à ouvrir l'écran des demandes.
        $this->notifier->leaveRequested($demande);

        return $demande;
    }
}
