<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Models\LeaveRequest;
use App\Shared\Http\ApiException;

/**
 * Supprimer une pause historique mal saisie — ex-Admin\LeaveController::destroyHistoricalLeave().
 *
 * ⚠️ Même restriction que la correction : seules les pauses d'origine ADMINISTRATIVE
 * (`admin_historical`, `legacy`) et terminées peuvent partir. Une pause issue d'une
 * demande d'agent a été vécue et approuvée — la supprimer effacerait une trace, pas une
 * faute de frappe.
 */
final class DeleteHistoricalLeave
{
    private const SOURCES_SUPPRIMABLES = ['admin_historical', 'legacy'];

    public function __invoke(LeaveRequest $pause): void
    {
        if (! in_array($pause->source, self::SOURCES_SUPPRIMABLES, true) || $pause->status !== 'completed') {
            throw new ApiException(
                409,
                'LEAVE_NOT_HISTORICAL',
                'Seules les pauses historiques peuvent être supprimées.'
            );
        }

        $pause->driver?->markLeaveDaysUsed(-($pause->effective_days ?? 0));

        $pause->delete();
    }
}
