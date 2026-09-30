<?php

namespace App\Domains\Finance\Application\Actions;

use App\Models\RemunerationStatement;
use App\Models\VehicleContract;
use Carbon\Carbon;

/**
 * Les brouillons d'un mois : un par contrat véhicule actif pendant ce mois, mois non
 * travaillés compris — la fiche d'un mois sans recette explique au propriétaire pourquoi
 * il ne reçoit rien, et la règle d'ordre de la validation exige qu'aucun mois ne manque.
 *
 * Relançable sans doublon : un contrat qui a déjà sa fiche vivante pour ce mois est sauté.
 */
final class GenerateRemunerationStatements
{
    public function __invoke(string $monthKey, ?string $vehicleContractId = null): int
    {
        if ($monthKey < (string) config('remuneration.first_month')) {
            return 0;
        }

        $start = Carbon::parse($monthKey.'-01')->startOfDay();
        $end = $start->copy()->endOfMonth();

        return VehicleContract::query()
            ->when($vehicleContractId, fn ($q) => $q->whereKey($vehicleContractId))
            ->whereDate('start_date', '<=', $end)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $start))
            ->where('status', '!=', 'cancelled')
            ->whereDoesntHave('remunerationStatements', fn ($q) => $q->whereDate('month', $start)->where('status', '!=', 'cancelled'))
            ->get()
            ->each(fn (VehicleContract $contract) => RemunerationStatement::create([
                'vehicle_contract_id' => $contract->id,
                'month' => $start->toDateString(),
                'status' => 'draft',
            ]))
            ->count();
    }
}
