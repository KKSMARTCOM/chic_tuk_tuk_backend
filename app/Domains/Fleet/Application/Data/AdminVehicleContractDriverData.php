<?php

namespace App\Domains\Fleet\Application\Data;

use App\Domains\Workforce\Domain\Enums\DriverContractStatus;
use App\Models\DriverContract;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/** Un contrat agent dans l'historique des agents d'un contrat véhicule. */
final class AdminVehicleContractDriverData extends BaseData
{
    public function __construct(
        public string $id,
        public ?string $driverId,
        public ?string $driverName,
        public ?string $startDate,
        public ?string $endDate,
        public int $contractMonths,
        #[TypeScriptType(DriverContractStatus::class)]
        public string $status,
        public int $paymentsCount,
        public ?string $endReason,
    ) {}

    public static function fromModel(DriverContract $contract): self
    {
        return new self(
            id: $contract->id,
            driverId: $contract->driver_id,
            driverName: $contract->driver?->user?->name,
            startDate: $contract->start_date?->toDateString(),
            endDate: $contract->end_date?->toDateString(),
            contractMonths: (int) $contract->contract_months,
            status: $contract->status,
            paymentsCount: (int) $contract->payments_count,
            endReason: $contract->end_reason,
        );
    }
}
