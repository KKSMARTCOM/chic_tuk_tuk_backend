<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Domains\Fleet\Application\Actions\CancelPause;
use App\Models\DriverContract;
use App\Models\VehiclePause;

/**
 * Défait ce que la fin d'un contrat agent a fait au véhicule et à l'agent (spec 2026-10-21,
 * §3.6 et §5) : la pause `agent_change` née de cette fin est ANNULÉE — pour le propriétaire,
 * l'immobilisation n'a pas eu lieu —, le véhicule reprend s'il n'a plus de pause en cours, et
 * la disponibilité de l'agent est recalculée.
 *
 * Ne touche ni au statut du contrat ni au compte de l'agent : la suppression et la
 * réouverture en décident chacune. Appelée dans la transaction de l'appelant.
 */
final class UndoDriverContractEnd
{
    public function __construct(
        private readonly CancelPause $cancelPause,
        private readonly SyncDriverAvailability $syncAvailability,
    ) {}

    public function __invoke(DriverContract $contract): void
    {
        $pause = VehiclePause::query()
            ->where('driver_contract_id', $contract->id)
            ->where('reason_type', 'agent_change')
            ->whereNull('end_date')
            ->first();

        // `CancelPause` remet le véhicule en service s'il n'a plus de pause en cours.
        if ($pause) {
            ($this->cancelPause)($pause);
        }

        if ($contract->driver) {
            ($this->syncAvailability)($contract->driver);
        }
    }
}
