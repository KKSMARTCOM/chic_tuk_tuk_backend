<?php

namespace App\Domains\Fleet\Application\Data;

use App\Models\DriverContract;
use App\Shared\Data\BaseData;

/** L'agent qui conduit le véhicule, et le solde de pauses de son contrat. */
final class AdminVehicleCurrentDriverData extends BaseData
{
    public function __construct(
        public string $driverId,
        public ?string $name,
        public ?string $phone,
        public ?string $startDate,
        public int $contractMonths,
        public int $accruedLeaveDays,
        public int $usedLeaveDays,
    ) {}

    public static function fromContract(DriverContract $contract): self
    {
        return new self(
            driverId: $contract->driver_id,
            name: $contract->driver?->user?->name,
            phone: $contract->driver?->user?->phone,
            startDate: $contract->start_date?->toDateString(),
            contractMonths: (int) $contract->contract_months,
            accruedLeaveDays: $contract->accrued_leave_days,
            usedLeaveDays: $contract->used_leave_days,
        );
    }
}
