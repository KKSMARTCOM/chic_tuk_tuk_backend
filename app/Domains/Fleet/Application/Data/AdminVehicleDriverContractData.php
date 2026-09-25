<?php

namespace App\Domains\Fleet\Application\Data;

use App\Models\DriverContract;
use App\Shared\Data\BaseData;

/** Un contrat agent dans l'historique des agents d'un véhicule. */
final class AdminVehicleDriverContractData extends BaseData
{
    public function __construct(
        public string $id,
        public ?string $driverId,
        public ?string $driverName,
        public ?string $startDate,
        public ?string $endDate,
        public int $contractMonths,
        public bool $isActive,
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
            isActive: $contract->status === 'active',
            endReason: $contract->end_reason,
        );
    }
}
