<?php

namespace App\Domains\Fleet\Application\Data;

use App\Models\VehicleContract;
use App\Shared\Data\BaseData;

/** Le contrat propriétaire-véhicule ACTIF d'un véhicule, sur la fiche du propriétaire. */
final class AdminOwnerVehicleContractData extends BaseData
{
    public function __construct(
        public string $id,
        public int $contractMonths,
        public float $totalAmount,
        /** Somme des paiements `completed` — l'accesseur `total_paid` du modèle. */
        public float $totalPaid,
        public ?string $startDate,
        public ?string $endDate,
        public float $unlimitedInternet,
        public float $spotifyPremium,
        public float $managerRemuneration,
        public ?string $notes,
    ) {}

    public static function fromModel(VehicleContract $contract): self
    {
        return new self(
            id: $contract->id,
            contractMonths: (int) $contract->contract_months,
            totalAmount: (float) $contract->total_amount,
            totalPaid: $contract->total_paid,
            startDate: $contract->start_date?->toDateString(),
            endDate: $contract->end_date?->toDateString(),
            unlimitedInternet: (float) $contract->unlimited_internet,
            spotifyPremium: (float) $contract->spotify_premium,
            managerRemuneration: (float) $contract->manager_remuneration,
            notes: $contract->notes,
        );
    }
}
