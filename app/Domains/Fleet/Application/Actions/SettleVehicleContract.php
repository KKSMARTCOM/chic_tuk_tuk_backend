<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Audit\Application\ActivityJournal;
use App\Domains\Notification\Application\Notifier;
use App\Models\VehicleContract;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Solder un contrat propriétaire dont les deux cumuls sont atteints (spec 2026-10-12, §4.2).
 *
 * La date de fin est le dernier jour du mois de la fiche qui a atteint le second cumul,
 * fixée d'office. Le contrat agent en cours n'est pas touché : l'agent va au bout du sien,
 * et ses versements d'après cette date reviennent à l'agence.
 */
final class SettleVehicleContract
{
    public function __construct(
        private readonly ActivityJournal $journal,
        private readonly Notifier $notifier,
    ) {}

    public function __invoke(VehicleContract $contract): VehicleContract
    {
        $contract = DB::transaction(function () use ($contract) {
            $contract = VehicleContract::query()->lockForUpdate()->findOrFail($contract->id);
            if (! $contract->isReadyToSettle()) {
                throw new ApiException(409, 'VEHICLE_CONTRACT_NOT_READY', 'Les deux cumuls du contrat ne sont pas atteints : il ne se solde pas encore.');
            }

            $last = $contract->revenue_reached_month->max($contract->charges_reached_month);
            $contract->update(['status' => 'completed', 'end_date' => $last->copy()->endOfMonth()->toDateString()]);
            $this->journal->vehicleContractCompleted($contract->load('vehicle'));

            return $contract;
        });

        // Après la transaction : une notification ne fait jamais échouer le solde.
        $this->notifier->vehicleContractSettled($contract->load('vehicle.owner'));

        return $contract;
    }
}
