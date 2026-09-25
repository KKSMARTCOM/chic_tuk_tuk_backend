<?php

namespace App\Domains\Fleet\Application\Data;

use App\Domains\Fleet\Domain\Enums\VehicleContractStatus;
use App\Models\VehicleContract;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/** Un contrat propriétaire-véhicule terminé ou annulé, dans l'historique du véhicule. */
final class AdminVehiclePastContractData extends BaseData
{
    public function __construct(
        public string $id,
        public float $totalAmount,
        public float $totalPaid,
        public ?string $startDate,
        public ?string $endDate,
        #[TypeScriptType(VehicleContractStatus::class)]
        public string $status,
    ) {}

    public static function fromModel(VehicleContract $contract): self
    {
        return new self(
            id: $contract->id,
            totalAmount: (float) $contract->total_amount,
            totalPaid: $contract->total_paid,
            startDate: $contract->start_date?->toDateString(),
            endDate: $contract->end_date?->toDateString(),
            status: $contract->status,
        );
    }
}
