<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Models\DriverContract;
use App\Models\InternalAssignment;
use App\Models\LeaveRequest;
use App\Models\RemunerationStatement;
use App\Models\VehicleContract;
use App\Shared\Http\ApiException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * « Annuler la fin du contrat » (spec 2026-10-21, §5) — l'administrateur seul
 * (`reopen-driver-contracts`) défait une fin de contrat agent saisie par erreur : le contrat
 * redevient actif, la pause `agent_change` est annulée, le véhicule reprend, le compte de
 * l'agent est rouvert et sa disponibilité recalculée.
 *
 * Ne génère aucun paiement : les jours entre la fin et la réouverture se génèrent par période
 * (`driver-contracts/{id}/payments/generate`) — `daysToGenerate()` les annonce. Les pauses
 * agent clôturées ou supprimées par la fin ne sont pas restaurées.
 */
final class ReopenDriverContract
{
    public function __construct(
        private readonly UndoDriverContractEnd $undoEnd,
        private readonly SyncDriverAvailability $syncAvailability,
    ) {}

    public function __invoke(DriverContract $contract): DriverContract
    {
        return DB::transaction(function () use ($contract) {
            // Verrous dans l'ordre du projet : contrat agent, puis contrat véhicule.
            $contract = DriverContract::query()->lockForUpdate()->findOrFail($contract->id);
            VehicleContract::query()->lockForUpdate()->find($contract->vehicle_contract_id);

            if ($refusal = $this->refusal($contract)) {
                throw $refusal;
            }

            $contract->update(['status' => 'active', 'end_date' => null, 'end_reason' => null, 'end_notes' => null]);
            ($this->undoEnd)($contract);
            $contract->driver->user?->update(['is_active' => true]);
            ($this->syncAvailability)($contract->driver->refresh());

            return $contract->refresh();
        });
    }

    /** Ce qui empêcherait la réouverture, dans l'ordre où on le dit ; `null` si rien. */
    public function refusal(DriverContract $contract): ?ApiException
    {
        if ($contract->status !== 'ended') {
            return new ApiException(409, 'DRIVER_CONTRACT_NOT_ENDED', 'Ce contrat n\'est pas terminé.');
        }

        // Le contrat propriétaire a pu être soldé ou terminé depuis le départ : on ne remet pas
        // un agent sur un contrat clos.
        if ($contract->vehicleContract?->status !== 'active') {
            return new ApiException(
                409,
                'DRIVER_CONTRACT_VEHICLE_CONTRACT_CLOSED',
                'Le contrat propriétaire de ce véhicule n\'est plus en cours.'
            );
        }

        $vehicleTaken = DriverContract::query()
            ->where('vehicle_id', $contract->vehicle_id)
            ->where('status', 'active')
            ->where('id', '!=', $contract->id)
            ->exists()
            || InternalAssignment::query()
                ->where('vehicle_contract_id', $contract->vehicle_contract_id)
                ->whereNull('end_date')
                ->exists();

        if ($vehicleTaken) {
            return new ApiException(409, 'DRIVER_CONTRACT_VEHICLE_TAKEN', 'Un autre agent a repris ce véhicule depuis.');
        }

        if ($contract->driver?->activeDriverContract()->exists()) {
            return new ApiException(409, 'DRIVER_HAS_ACTIVE_CONTRACT', 'Cet agent a déjà un autre contrat en cours.');
        }

        // Une fiche validée a figé les jours de son mois : rouvrir réécrirait des jours qu'elle
        // a comptés sans agent.
        $statementValidated = $contract->end_date !== null && RemunerationStatement::query()
            ->where('vehicle_contract_id', $contract->vehicle_contract_id)
            ->where('status', 'validated')
            ->whereDate('month', '>=', $contract->end_date->copy()->startOfMonth())
            ->exists();

        if ($statementValidated) {
            return new ApiException(
                409,
                'DRIVER_CONTRACT_STATEMENT_VALIDATED',
                'Une fiche de rémunération validée couvre déjà des jours après la fin de ce contrat.'
            );
        }

        return null;
    }

    /** Jours ouvrés entre le lendemain de la fin et aujourd'hui inclus, à générer après réouverture. */
    public function daysToGenerate(DriverContract $contract): int
    {
        if ($contract->end_date === null) {
            return 0;
        }

        $from = $contract->end_date->copy()->addDay()->startOfDay();
        $today = Carbon::today();

        return $from->gt($today) ? 0 : LeaveRequest::countBusinessDays($from, $today);
    }
}
