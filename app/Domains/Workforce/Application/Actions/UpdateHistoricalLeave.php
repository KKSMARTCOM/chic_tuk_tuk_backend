<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Models\LeaveRequest;
use App\Shared\Http\ApiException;
use Carbon\Carbon;

/**
 * Corriger une pause historique mal saisie — ex-Admin\LeaveController::updateHistoricalLeave().
 *
 * ⚠️ SEULES les pauses historiques sont modifiables, et le contrôle porte sur `source`
 * autant que sur le statut. Une pause terminée issue d'une demande d'agent
 * (`driver_request`) a été vécue : la réécrire changerait un fait, pas une saisie. Seules
 * `admin_historical` et `legacy` — les deux origines administratives — s'y prêtent.
 */
final class UpdateHistoricalLeave
{
    /** Les origines qu'un administrateur a saisies lui-même, et peut donc corriger. */
    private const SOURCES_CORRIGEABLES = ['admin_historical', 'legacy'];

    public function __invoke(LeaveRequest $pause, string $dateDeDebut, int $joursDemandes): LeaveRequest
    {
        if (! in_array($pause->source, self::SOURCES_CORRIGEABLES, true) || $pause->status !== 'completed') {
            throw new ApiException(
                409,
                'LEAVE_NOT_HISTORICAL',
                'Seules les pauses historiques peuvent être modifiées.'
            );
        }

        $debut = Carbon::parse($dateDeDebut)->startOfDay();
        $fin = LeaveRequest::addBusinessDays($debut, $joursDemandes);

        if ($fin->gte(now()->startOfDay())) {
            throw new ApiException(
                422,
                'LEAVE_NOT_ENTIRELY_PAST',
                "Une pause historique doit rester entièrement terminée avant aujourd'hui."
            );
        }

        $agent = $pause->driver;

        // ⚠️ L'ancien décompte est RETIRÉ avant d'appliquer le nouveau : sans cela, une
        // correction s'ajouterait à la saisie d'origine au lieu de la remplacer.
        $agent?->markLeaveDaysUsed(-($pause->effective_days ?? 0));

        $pause->update([
            'start_date' => $debut->toDateString(),
            'requested_days' => $joursDemandes,
            'end_date' => $fin->toDateString(),
            'effective_days' => $joursDemandes,
        ]);

        $agent?->markLeaveDaysUsed($joursDemandes);

        return $pause->refresh();
    }
}
