<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Domains\Notification\Application\Notifier;
use App\Models\LeaveRequest;
use App\Shared\Http\ApiException;

/**
 * Refuser une demande de pause — ex-Admin\LeaveController::rejectRequest().
 *
 * ⚠️ Le motif est OBLIGATOIRE, et d'au moins cinq caractères. C'est la règle du
 * formulaire Blade, et elle a du sens : le motif voyage jusqu'à l'agent, dans sa
 * notification comme sur son écran. Un refus sans motif paraît arbitraire.
 *
 * ⚠️ Une demande refusée ne bloque PAS une nouvelle demande — seuls `pending` et
 * `ongoing` le font. L'agent peut donc reproposer d'autres dates immédiatement.
 */
final class RejectLeaveRequest
{
    public function __construct(private readonly Notifier $notifier) {}

    public function __invoke(LeaveRequest $demande, string $motif): LeaveRequest
    {
        if ($demande->status !== 'pending') {
            throw new ApiException(
                409,
                'LEAVE_NOT_PENDING',
                'Seule une demande en attente peut être refusée.'
            );
        }

        $demande->update(['status' => 'rejected', 'rejection_reason' => $motif]);

        $this->notifier->leaveRejected($demande->refresh());

        return $demande;
    }
}
