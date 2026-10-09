<?php

namespace App\Domains\Fleet\Application\Data;

use App\Models\DriverContract;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/** GET /admin/vehicles/{vehicle} — la fiche d'un véhicule, ex-`pages.admin.vehicles.show`. */
final class AdminVehicleDetailData extends BaseData
{
    public function __construct(
        public string $id,
        public string $vehicleNumber,
        #[LiteralTypeScriptType("'moto' | 'tricycle' | 'car'")]
        public string $vehicleType,
        public ?string $notes,
        public bool $isActive,
        public ?VehiclePauseData $activePause,
        public ?AdminVehicleActiveContractData $contract,
        /** @var array<int, AdminVehiclePastContractData> */
        public array $pastContracts,
        /** @var array<int, AdminVehicleDriverContractData> */
        public array $driverHistory,
        public ?AdminVehicleOwnerData $owner,
        public ?AdminVehicleCurrentDriverData $currentDriver,
        /** @var array<int, VehiclePauseData> */
        public array $pauses,
        /** L'agent interne en cours, sans paiement (2026-10-09). */
        public ?AdminInternalAssignmentData $currentInternalAssignment,
    ) {}

    public static function fromModel(Vehicle $vehicle): self
    {
        return new self(
            id: $vehicle->id,
            vehicleNumber: $vehicle->vehicle_number,
            vehicleType: $vehicle->vehicle_type,
            notes: $vehicle->notes,
            isActive: (bool) $vehicle->is_active,
            activePause: $vehicle->activePause ? VehiclePauseData::fromModel($vehicle->activePause) : null,
            // Le contrat vivant : actif, ou en attente de son premier agent (2026-10-09).
            contract: $vehicle->liveVehicleContract
                ? AdminVehicleActiveContractData::fromModel($vehicle->liveVehicleContract)
                : null,
            pastContracts: $vehicle->vehicleContracts
                ->whereNotIn('status', ['active', 'pending'])
                ->sortByDesc('start_date')
                ->map(fn (VehicleContract $c) => AdminVehiclePastContractData::fromModel($c))
                ->values()
                ->all(),
            driverHistory: $vehicle->driverContracts
                ->sortByDesc('start_date')
                ->map(fn (DriverContract $c) => AdminVehicleDriverContractData::fromModel($c))
                ->values()
                ->all(),
            owner: $vehicle->owner ? AdminVehicleOwnerData::fromModel($vehicle->owner) : null,
            currentDriver: $vehicle->activeDriverContract
                ? AdminVehicleCurrentDriverData::fromContract($vehicle->activeDriverContract)
                : null,
            pauses: VehiclePauseData::historyOf($vehicle),
            currentInternalAssignment: ($internal = $vehicle->liveVehicleContract?->activeInternalAssignment)
                ? AdminInternalAssignmentData::fromModel($internal)
                : null,
        );
    }
}
