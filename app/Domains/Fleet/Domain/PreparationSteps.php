<?php

namespace App\Domains\Fleet\Domain;

use App\Models\PreparationStepTemplate;
use App\Models\VehicleContract;
use App\Models\VehicleContractPreparationStep;

/**
 * Les règles de la préparation d'un véhicule (spec 2026-10-20, §4).
 *
 * ⚠️ Appelée par la migration : aucune fabrique ici, des `create()` seulement.
 */
final class PreparationSteps
{
    public const AGENT_ARRIVAL_LABEL = 'Arrivée du premier agent';

    /** La liste par défaut, puis l'arrivée du premier agent — rien si le contrat en a déjà. */
    public static function seedFor(VehicleContract $contract): void
    {
        if (VehicleContractPreparationStep::where('vehicle_contract_id', $contract->id)->exists()) {
            return;
        }

        $templates = PreparationStepTemplate::query()->orderBy('position')->get();
        foreach ($templates->values() as $i => $template) {
            VehicleContractPreparationStep::create([
                'vehicle_contract_id' => $contract->id, 'label' => $template->label, 'position' => $i + 1,
            ]);
        }
        VehicleContractPreparationStep::create([
            'vehicle_contract_id' => $contract->id, 'label' => self::AGENT_ARRIVAL_LABEL,
            'position' => $templates->count() + 1, 'is_agent_arrival' => true,
        ]);
    }

    /** Les contrats déjà en attente, sans étape (spec §3.3). Rend le nombre traité. */
    public static function backfillPending(): int
    {
        $contracts = VehicleContract::query()->where('status', 'pending')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('vehicle_contract_preparation_steps as s')
                ->whereColumn('s.vehicle_contract_id', 'vehicle_contracts.id'))
            ->get();
        $contracts->each(fn (VehicleContract $contract) => self::seedFor($contract));

        return $contracts->count();
    }

    /** Les étapes ne se modifient que tant que le contrat est en attente (§4.2). */
    public static function isEditable(VehicleContract $contract): bool
    {
        return $contract->status === 'pending';
    }
}
