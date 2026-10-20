<?php

namespace App\Domains\Fleet\Application\Data;

use App\Domains\Fleet\Domain\VehicleOwnerState;
use App\Models\Vehicle;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

final class OwnerVehicleSummaryData extends BaseData
{
    public function __construct(
        public string $id,
        public string $vehicleNumber,
        public ?string $vehicleType,
        /** Actif, En pause, Immobilisé — en attente d'un nouvel agent (spec §6.1) —, En attente de mise en service (2026-10-09), ou Soldé (2026-10-12). */
        #[LiteralTypeScriptType('"active" | "paused" | "immobilized" | "pending" | "completed"')]
        public string $state,
        /** Le motif de la pause en cours, affiché à côté de l'état. */
        public ?string $pauseReasonLabel,
        public ?OwnerContractSummaryData $contract,
        /** L'avancement de la préparation, tant que le véhicule est en attente (spec 2026-10-20, §6.1). */
        public ?int $preparationDone = null,
        public ?int $preparationTotal = null,
    ) {}

    /** Attend un véhicule ayant chargé `activeVehicleContract`, `liveVehicleContract`, `settledVehicleContract` et `activePause`. */
    public static function fromModel(Vehicle $vehicle): self
    {
        $settled = $vehicle->liveVehicleContract === null ? $vehicle->settledVehicleContract : null;
        $contract = $vehicle->activeVehicleContract ?? $settled;
        // Soldé : l'agent qui continue roule pour l'agence, ses pauses ne sont pas celles du
        // propriétaire (spec 2026-10-12, §7.2).
        $pause = $settled ? null : $vehicle->activePause;
        $pending = $vehicle->liveVehicleContract?->status === 'pending' ? $vehicle->liveVehicleContract : null;

        return new self(
            id: $vehicle->id,
            vehicleNumber: $vehicle->vehicle_number,
            vehicleType: $vehicle->vehicle_type,
            // En attente de son premier agent : ni chiffres ni pause à montrer (2026-10-09).
            state: match (true) {
                $settled !== null => 'completed',
                $vehicle->liveVehicleContract?->status === 'pending' => 'pending',
                default => VehicleOwnerState::of($pause),
            },
            pauseReasonLabel: $pause?->reason_label,
            // Le véhicule peut n'avoir aucun contrat actif : c'est un cas réel, que le
            // Blade traite déjà par « Aucun contrat actif pour ce véhicule ».
            contract: $contract ? OwnerContractSummaryData::fromModel($contract) : null,
            preparationDone: $pending ? $pending->preparationSteps->whereNotNull('done_on')->count() : null,
            preparationTotal: $pending?->preparationSteps->count(),
        );
    }
}
