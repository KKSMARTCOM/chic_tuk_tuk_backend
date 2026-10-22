<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Application\Data\DisbursableContractData;
use App\Domains\Finance\Application\Data\DisbursableContractsData;
use App\Models\OwnerDisbursement;
use App\Models\RemunerationStatement;
use App\Models\VehicleContract;

/**
 * Les contrats propriétaires à qui verser, pour l'ajout d'un paiement (essai staging du
 * 2026-10-22) : ceux qui ont au moins une fiche validée — sans elle, rien n'est dû. Le dû suit
 * le calcul de `BuildDisbursementSummary`, en deux sommes groupées plutôt qu'un appel par contrat.
 */
final class ListDisbursableContracts
{
    public function __invoke(): DisbursableContractsData
    {
        $earned = RemunerationStatement::query()->where('status', 'validated')
            ->groupBy('vehicle_contract_id')->selectRaw('vehicle_contract_id, sum(balance_due) as total')
            ->pluck('total', 'vehicle_contract_id');
        $disbursed = OwnerDisbursement::query()->where('status', 'recorded')
            ->groupBy('vehicle_contract_id')->selectRaw('vehicle_contract_id, sum(amount) as total')
            ->pluck('total', 'vehicle_contract_id');

        $contracts = VehicleContract::query()->with('vehicle.owner')->whereIn('id', $earned->keys())->get()
            ->map(fn (VehicleContract $c) => new DisbursableContractData(
                id: $c->id,
                ownerName: $c->vehicle?->owner?->name,
                vehicleNumber: $c->vehicle?->vehicle_number,
                status: (string) $c->status,
                outstanding: round((float) $earned[$c->id] - (float) ($disbursed[$c->id] ?? 0), 2),
            ))
            ->sortBy(fn (DisbursableContractData $c) => mb_strtolower(($c->ownerName ?? '').' '.($c->vehicleNumber ?? '')))
            ->values()->all();

        return new DisbursableContractsData($contracts);
    }
}
