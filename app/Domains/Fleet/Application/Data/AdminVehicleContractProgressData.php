<?php

namespace App\Domains\Fleet\Application\Data;

use App\Models\VehicleContract;
use App\Shared\Data\BaseData;

/** Le contrat en cours d'un véhicule, réduit à sa barre de progression dans la liste. */
final class AdminVehicleContractProgressData extends BaseData
{
    public function __construct(
        public float $totalPaid,
        public float $totalAmount,
        public int $progressPercentage,
    ) {}

    public static function fromModel(VehicleContract $contract): self
    {
        return new self(
            totalPaid: $contract->total_paid,
            totalAmount: (float) $contract->total_amount,
            progressPercentage: $contract->progress_percentage,
        );
    }
}
