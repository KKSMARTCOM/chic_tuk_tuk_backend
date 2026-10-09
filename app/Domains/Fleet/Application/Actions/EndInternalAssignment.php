<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Models\InternalAssignment;
use App\Models\User;
use App\Shared\Http\ApiException;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/** Terminer une affectation interne, à la main ou à l'arrivée d'un contrat agent. */
final class EndInternalAssignment
{
    public function __invoke(InternalAssignment $assignment, string $endDate, string $reason, ?User $by): InternalAssignment
    {
        $assignment->refresh();
        if ($assignment->end_date !== null) {
            throw new ApiException(409, 'INTERNAL_ASSIGNMENT_ENDED', 'Cette affectation interne est déjà terminée.');
        }

        $end = Carbon::parse($endDate)->startOfDay();
        if ($end->lt($assignment->start_date->copy()->startOfDay())) {
            throw ValidationException::withMessages(['end_date' => 'La fin ne peut pas précéder le début de l\'affectation ('.$assignment->start_date->format('d/m/Y').').']);
        }

        $assignment->update(['end_date' => $end->toDateString(), 'ended_reason' => $reason, 'ended_by' => $by?->id]);

        return $assignment->refresh();
    }
}
