<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Domains\Workforce\Application\Data\EndDriverContractData;
use App\Models\DriverContract;
use App\Models\LeaveRequest;
use App\Models\VehiclePause;
use App\Shared\Http\ApiException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Terminer un contrat agent — ex-Admin\DriverContractController::end().
 *
 * ⚠️ Effets de bord, dans `endContract()` ci-dessous (ex-`DriverContractService::end()`) : les
 * pauses agent sont clôturées ou supprimées, le véhicule est désactivé, une pause véhicule
 * automatique « changement d'agent » s'ouvre, le compteur de pauses de l'agent est remis à
 * zéro, et — depuis le 2026-10-21 — l'agent devient indisponible et son compte est fermé.
 */
final class EndDriverContract
{
    public function __construct(
        private readonly EndLeave $endLeave,
        private readonly DeleteLeave $deleteLeave,
        private readonly SyncDriverAvailability $syncAvailability,
    ) {}

    public function __invoke(DriverContract $contract, EndDriverContractData $data): DriverContract
    {
        return $this->endContract($contract, $data->toServicePayload());
    }

    /**
     * Termine un contrat actif : pause véhicule « changement d'agent », véhicule désactivé,
     * compteur de pauses de l'agent remis à zéro.
     *
     * Corrigé le 2026-09-26 : un contrat déjà terminé pouvait l'être une seconde fois, ce
     * qui créait une seconde pause véhicule automatique.
     */
    private function endContract(DriverContract $contract, array $data): DriverContract
    {
        if ($contract->status !== 'active') {
            throw new ApiException(409, 'DRIVER_CONTRACT_NOT_ACTIVE', 'Ce contrat est déjà terminé.');
        }

        // ⚠️ Corrigé le 2026-10-06 : deux contrats de production se sont terminés avant
        // d'avoir commencé, ce qui sortait tous leurs jours du contrat.
        $endDate = Carbon::parse($data['end_date'] ?? now()->toDateString())->startOfDay();
        if ($endDate->lt($contract->start_date->copy()->startOfDay())) {
            throw new ApiException(
                422,
                'DRIVER_CONTRACT_END_BEFORE_START',
                'La date de fin ne peut pas précéder le début du contrat ('.$contract->start_date->format('d/m/Y').').'
            );
        }

        // ⚠️ Terminer, c'est constater un départ ARRIVÉ (2026-10-21), comme pour la clôture d'une
        // pause (`LEAVE_END_IN_FUTURE`) : une fin future fermait le compte d'office, et les pauses
        // de l'agent ne pouvaient pas être réglées à une date qui n'est pas encore venue.
        if ($endDate->gt(now()->startOfDay())) {
            throw new ApiException(
                422,
                'DRIVER_CONTRACT_END_IN_FUTURE',
                'La date de fin ne peut pas être dans le futur : terminez le contrat le jour du départ.'
            );
        }

        return DB::transaction(function () use ($contract, $data, $endDate) {
            // Calculé AVANT de clore les pauses : il lit les pauses en cours ET terminées, le
            // résultat ne change donc pas, mais l'ordre le rend évident.
            $firstDayWithoutAgent = $this->firstDayWithoutAgent($contract, $endDate);

            $this->settleLeaves($contract, $endDate);

            $contract->update([
                'status' => 'ended',
                'end_date' => $data['end_date'] ?? now()->toDateString(),
                'end_reason' => $data['end_reason'],
                'end_notes' => $data['end_notes'] ?? null,
            ]);

            // Réinitialiser les jours de pause utilisés pour le conducteur
            $contract->driver->update([
                'leave_days_used' => 0,
                'leave_dates' => [],
            ]);

            // Marquer le véhicule comme inactif
            $contract->vehicle->update([
                'is_active' => false,
            ]);

            // Le départ ferme le compte (spec 2026-10-21, §4.2) : seul un administrateur le
            // rouvrira. Plus de contrat, donc plus de disponibilité.
            $contract->driver->user?->update(['is_active' => false]);
            ($this->syncAvailability)($contract->driver->refresh());

            // Créer une pause véhicule pour changement d'agent
            VehiclePause::create([
                'vehicle_id' => $contract->vehicle_id,
                'vehicle_contract_id' => $contract->vehicle_contract_id,
                'driver_contract_id' => $contract->id,
                'start_date' => $firstDayWithoutAgent->toDateString(),
                'end_date' => null, // sera fermée à la création du prochain contrat agent
                'reason_type' => 'agent_change',
                'reason_notes' => $data['end_reason'].(! empty($data['end_notes']) ? ' — '.$data['end_notes'] : ''),
                'is_auto' => true,
            ]);

            return $contract->refresh();
        });
    }

    /**
     * Aucune pause agent ne survit au contrat qu'elle concerne (spec 2026-10-21, §4.2) : celle
     * qui a commencé au plus tard à la date de fin — jamais future — est clôturée à cette date ;
     * celle qui n'a pas commencé est supprimée, ses effets défaits.
     */
    private function settleLeaves(DriverContract $contract, Carbon $endDate): void
    {
        $leaves = LeaveRequest::query()
            ->where('driver_id', $contract->driver_id)
            ->where('status', 'ongoing')
            ->get();

        foreach ($leaves as $leave) {
            if ($leave->start_date->copy()->startOfDay()->lte($endDate)) {
                ($this->endLeave)($leave, $endDate->toDateString(), notifyOwner: false);
            } else {
                ($this->deleteLeave)($leave);
            }
        }
    }

    /**
     * Le premier jour où le véhicule est sans agent (règle du 2026-10-06).
     *
     * L'agent garde le tricycle jusqu'au bout : le jour où il le dépose est DÛ, et le
     * véhicule n'est sans agent qu'à partir du lendemain. Sauf s'il sortait d'une pause :
     * il la consomme, la rupture tombe le jour ouvré suivant, et ce jour n'est pas dû.
     */
    private function firstDayWithoutAgent(DriverContract $contract, Carbon $endDate): Carbon
    {
        $previousBusinessDay = $endDate->copy()->subDay();
        while ($previousBusinessDay->isWeekend()) {
            $previousBusinessDay->subDay();
        }

        $leftAPause = LeaveRequest::query()
            ->where('driver_contract_id', $contract->id)
            ->whereIn('status', ['ongoing', 'completed'])
            ->whereDate('start_date', '<=', $endDate)
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $previousBusinessDay))
            ->exists();

        return $leftAPause ? $endDate->copy() : $endDate->copy()->addDay();
    }
}
