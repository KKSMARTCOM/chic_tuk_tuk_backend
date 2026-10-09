<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\AdminInternalAssignmentData;
use App\Domains\Fleet\Application\Data\AdminVehicleContractDetailData;
use App\Domains\Fleet\Application\Data\AdminVehicleContractDriverData;
use App\Domains\Fleet\Application\Data\AdminVehicleContractListItemData;
use App\Domains\Fleet\Application\Data\AdminVehicleContractMonthData;
use App\Domains\Fleet\Application\Data\AdminVehicleContractPartyData;
use App\Domains\Fleet\Application\Data\VehiclePauseData;
use App\Models\DriverContract;
use App\Models\InternalAssignment;

/**
 * La fiche d'un contrat propriétaire-véhicule — ex-Admin\VehicleContractController::show().
 *
 * Corrigé le 2026-09-26 : les paiements par mois additionnaient le montant BRUT de
 * TOUS les paiements, annulés et échoués compris, quand le « Total payé » de la même
 * fiche ne compte que les paiements `completed`, en net. Les mois reprennent la règle du
 * total, et leur somme lui est égale.
 */
final class ShowVehicleContractDetail
{
    public function __invoke(string $contractId): AdminVehicleContractDetailData
    {
        $contract = ListVehicleContracts::query()
            ->with([
                'driverContracts' => fn ($query) => $query->withCount('payments')->with(['driver.user', 'leaveRequests'])->orderByDesc('start_date'),
                'pauses',
                'internalAssignments' => fn ($query) => $query->with(['driver.user', 'vehicleContract.vehicle']),
            ])
            ->findOrFail($contractId);

        $paymentsByMonth = $contract->payments()
            ->where('status', 'completed')
            ->selectRaw("TO_CHAR(DATE_TRUNC('month', payment_date), 'YYYY-MM') as month, SUM(net_amount) as total")
            ->groupByRaw("DATE_TRUNC('month', payment_date)")
            ->orderByRaw("DATE_TRUNC('month', payment_date) DESC")
            ->toBase()
            ->get();

        $activeDriverContract = $contract->driverContracts->firstWhere('status', 'active');
        $activeDriverUser = $activeDriverContract?->driver?->user;

        return new AdminVehicleContractDetailData(
            contract: AdminVehicleContractListItemData::fromModel($contract),
            paymentsCount: (int) $contract->payments_count,
            paymentsByMonth: $paymentsByMonth
                ->map(fn ($row) => new AdminVehicleContractMonthData($row->month, (float) $row->total))
                ->all(),
            driverContracts: $contract->driverContracts
                ->map(fn (DriverContract $driverContract) => AdminVehicleContractDriverData::fromModel($driverContract))
                ->all(),
            pauses: VehiclePauseData::history(
                $contract->pauses,
                $contract->driverContracts->flatMap(fn (DriverContract $driverContract) => $driverContract->leaveRequests),
            ),
            currentDriver: $activeDriverUser
                ? AdminVehicleContractPartyData::fromUser($activeDriverContract->driver_id, $activeDriverUser)
                : null,
            currentDriverSince: $activeDriverContract?->start_date?->toDateString(),
            currentInternalAssignment: ($current = $contract->internalAssignments->firstWhere('end_date', null))
                ? AdminInternalAssignmentData::fromModel($current)
                : null,
            internalAssignments: $contract->internalAssignments
                ->filter(fn (InternalAssignment $assignment) => $assignment->end_date !== null)
                ->map(fn (InternalAssignment $assignment) => AdminInternalAssignmentData::fromModel($assignment))
                ->values()
                ->all(),
        );
    }
}
