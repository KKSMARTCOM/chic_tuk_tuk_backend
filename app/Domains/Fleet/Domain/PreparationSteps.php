<?php

namespace App\Domains\Fleet\Domain;

use App\Models\PreparationStepTemplate;
use App\Models\VehicleContract;
use App\Models\VehicleContractPreparationStep;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

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

    /** Le contrat est mis en service : l'arrivée du premier agent est cochée à cette date (§4.3, §4.3 bis). Sans notification. */
    public static function markAgentArrival(VehicleContract $contract, string $date): void
    {
        VehicleContractPreparationStep::where('vehicle_contract_id', $contract->id)->where('is_agent_arrival', true)
            ->update(['done_on' => $date, 'done_by' => null, 'done_at' => now()]);
    }

    /** Le contrat repasse en attente (§4.3 bis) : l'arrivée se décoche, ou la liste naît s'il n'en a pas. */
    public static function reopen(VehicleContract $contract): void
    {
        VehicleContractPreparationStep::where('vehicle_contract_id', $contract->id)->where('is_agent_arrival', true)
            ->update(['done_on' => null, 'done_by' => null, 'done_at' => null]);
        self::seedFor($contract);
    }

    /** 409 hors attente : les étapes d'un contrat en service sont un historique (§4.2). */
    public static function assertEditable(VehicleContract $contract): void
    {
        if (! self::isEditable($contract)) {
            throw new ApiException(409, 'PREPARATION_LOCKED', 'Le véhicule est en service : sa préparation ne se modifie plus.');
        }
    }

    /** L'arrivée du premier agent ne se touche pas à la main (§4.2). */
    public static function assertNotAgentArrival(VehicleContractPreparationStep $step, string $field): void
    {
        if ($step->is_agent_arrival) {
            throw ValidationException::withMessages([$field => 'L\'arrivée du premier agent se coche d\'elle-même, au démarrage de son contrat.']);
        }
    }

    /**
     * Retire la preuve d'une étape (sans enregistrer). ⚠️ Le fichier ne part qu'APRÈS la
     * validation de la transaction (relecture finale) : une écriture annulée garde la preuve
     * que la base désigne encore — une facture ou une attestation ne se retrouve pas.
     */
    public static function forgetProof(VehicleContractPreparationStep $step): void
    {
        if ($path = $step->proof_path) {
            DB::afterCommit(fn () => Storage::disk('local')->delete($path));
        }
        $step->fill(['proof_path' => null, 'proof_original_name' => null, 'proof_mime_type' => null, 'proof_size' => null]);
    }

    /** Les étapes ne se modifient que tant que le contrat est en attente (§4.2). */
    public static function isEditable(VehicleContract $contract): bool
    {
        return $contract->status === 'pending';
    }
}
