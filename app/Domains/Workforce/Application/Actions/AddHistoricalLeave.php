<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Models\Driver;
use App\Models\LeaveRequest;
use App\Shared\Http\ApiException;
use Carbon\Carbon;

/**
 * Saisir une pause PASSÉE, déjà terminée — ex-Admin\LeaveController::addHistoricalLeave().
 *
 * Sert à régulariser un dossier : une absence prise avant la mise en service de
 * l'application, ou oubliée sur le moment. Elle naît directement `completed`, avec ses
 * jours effectifs.
 *
 * ⚠️ Elle doit être ENTIÈREMENT passée. Une saisie qui déborderait sur aujourd'hui serait
 * en réalité une pause en cours, et devrait créer la pause véhicule et rendre l'agent
 * indisponible — ce que cette action ne fait pas. Le refus est donc structurel, pas
 * cosmétique.
 *
 * ⚠️ L'absence de contrat actif est refusée EXPLICITEMENT. Le contrôleur Blade lisait
 * `$contract->start_date` sans vérifier que `$contract` existe : sur un agent sans
 * contrat, la requête mourait sur un appel de méthode sur null, en 500 sans message.
 * Corrigé plutôt que transposé, selon la règle du projet.
 */
final class AddHistoricalLeave
{
    public function __invoke(Driver $agent, string $dateDeDebut, int $joursDemandes, string $auteurId): LeaveRequest
    {
        $contrat = $agent->activeDriverContract;

        if (! $contrat) {
            throw new ApiException(409, 'LEAVE_NO_ACTIVE_CONTRACT', 'Aucun contrat actif pour cet agent.');
        }

        $debut = Carbon::parse($dateDeDebut)->startOfDay();

        if ($debut->lt(Carbon::parse($contrat->start_date)->startOfDay())) {
            throw new ApiException(
                422,
                'LEAVE_BEFORE_CONTRACT_START',
                'La date de début de la pause ne peut pas être antérieure à la date de début du contrat ('
                .Carbon::parse($contrat->start_date)->format('d/m/Y').').'
            );
        }

        $fin = LeaveRequest::addBusinessDays($debut, $joursDemandes);

        if ($fin->gte(now()->startOfDay())) {
            throw new ApiException(
                422,
                'LEAVE_NOT_ENTIRELY_PAST',
                "Une pause historique doit être entièrement terminée avant aujourd'hui."
            );
        }

        $pause = LeaveRequest::create([
            'driver_id' => $agent->id,
            'driver_contract_id' => $contrat->id,
            'start_date' => $debut->toDateString(),
            'requested_days' => $joursDemandes,
            'end_date' => $fin->toDateString(),
            // Une pause passée est consommée en entier : les jours effectifs valent les
            // jours demandés, faute de quoi elle ne compterait pas dans le solde.
            'effective_days' => $joursDemandes,
            'status' => 'completed',
            'source' => 'admin_historical',
            'created_by' => $auteurId,
        ]);

        $agent->markLeaveDaysUsed($joursDemandes);

        return $pause;
    }
}
