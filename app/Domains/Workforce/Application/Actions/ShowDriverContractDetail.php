<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Domains\Fleet\Application\Data\AdminVehicleContractMonthData;
use App\Domains\Fleet\Application\Data\AdminVehicleContractPartyData;
use App\Domains\Fleet\Application\Data\VehiclePauseData;
use App\Domains\Workforce\Application\Data\AdminDriverContractDetailData;
use App\Domains\Workforce\Application\Data\AdminDriverContractListItemData;
use App\Models\VehiclePause;

/**
 * La fiche d'un contrat agent — ex-Admin\DriverContractController::show().
 *
 * Corrigé le 2026-09-26 : le total et les paiements par mois additionnaient TOUS les
 * paiements, annulés et échoués compris. Ils ne comptent plus que les paiements validés,
 * au montant payé par l'agent (`amount`), regroupés par mois de paiement comme au Blade.
 */
final class ShowDriverContractDetail
{
    public function __invoke(string $contractId): AdminDriverContractDetailData
    {
        $contract = ListDriverContracts::query()
            ->with([
                'vehicle.owner',
                'vehicleContract',
                'vehiclePauses' => fn ($query) => $query->orderByDesc('start_date'),
            ])
            ->findOrFail($contractId);

        $completed = $contract->payments()->where('status', 'completed');

        $paymentsByMonth = (clone $completed)
            ->selectRaw("TO_CHAR(DATE_TRUNC('month', COALESCE(payment_month, payment_date)), 'YYYY-MM') as month, SUM(amount) as total")
            ->groupByRaw("DATE_TRUNC('month', COALESCE(payment_month, payment_date))")
            ->orderByRaw("DATE_TRUNC('month', COALESCE(payment_month, payment_date)) DESC")
            ->toBase()
            ->get();

        $owner = $contract->vehicle?->owner;
        $vehicleContract = $contract->vehicleContract;

        return new AdminDriverContractDetailData(
            contract: AdminDriverContractListItemData::fromModel($contract),
            paymentsCount: (int) $contract->payments_count,
            totalPaid: (float) (clone $completed)->sum('amount'),
            paymentsByMonth: $paymentsByMonth
                ->map(fn ($row) => new AdminVehicleContractMonthData($row->month, (float) $row->total))
                ->all(),
            pauses: $contract->vehiclePauses
                ->map(fn (VehiclePause $pause) => VehiclePauseData::fromModel($pause))
                ->all(),
            vehicleColor: $contract->vehicle?->color,
            owner: $owner ? AdminVehicleContractPartyData::fromUser($owner->id, $owner) : null,
            vehicleContractId: $vehicleContract?->id,
            vehicleContractStatus: $vehicleContract?->status,
            vehicleContractMonths: $vehicleContract?->contract_months,
            vehicleContractNotes: $vehicleContract?->notes,
        );
    }
}
