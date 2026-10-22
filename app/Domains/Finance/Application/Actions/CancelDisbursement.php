<?php

namespace App\Domains\Finance\Application\Actions;

use App\Models\OwnerDisbursement;
use App\Models\User;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Annuler un versement (spec 2026-10-22-versement-proprietaire, §4.3) : il ne compte plus et
 * disparaît chez le propriétaire, mais reste visible chez l'admin avec son motif. La preuve
 * reste sur le disque.
 */
final class CancelDisbursement
{
    public function __invoke(OwnerDisbursement $disbursement, string $reason, User $actor): OwnerDisbursement
    {
        return DB::transaction(function () use ($disbursement, $reason, $actor) {
            $disbursement = OwnerDisbursement::query()->lockForUpdate()->findOrFail($disbursement->id);
            if ($disbursement->status !== 'recorded') {
                throw new ApiException(409, 'DISBURSEMENT_CANCELLED', 'Ce versement est déjà annulé.');
            }

            $disbursement->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancellation_reason' => $reason,
            ]);

            return $disbursement;
        });
    }
}
