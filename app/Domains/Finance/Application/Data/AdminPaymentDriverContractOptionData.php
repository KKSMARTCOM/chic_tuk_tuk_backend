<?php

namespace App\Domains\Finance\Application\Data;

use App\Models\DriverContract;
use App\Shared\Data\BaseData;

/** Un contrat d'agent, en cours ou terminé, proposé au paiement et à la génération (2026-10-01). */
final class AdminPaymentDriverContractOptionData extends BaseData
{
    public function __construct(
        public string $id,
        public ?string $vehicleNumber,
        public string $startDate,
        public ?string $endDate,
        public string $status,
        public ?string $endReason,
        public bool $isActive,
    ) {}

    public static function fromModel(DriverContract $contract): self
    {
        return new self(
            id: $contract->id,
            vehicleNumber: $contract->vehicle?->vehicle_number,
            startDate: $contract->start_date->toDateString(),
            endDate: $contract->end_date?->toDateString(),
            status: (string) $contract->status,
            endReason: $contract->end_reason,
            isActive: $contract->isActive(),
        );
    }
}
