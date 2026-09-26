<?php

namespace App\Domains\Fleet\Application\Data;

use App\Domains\Fleet\Domain\Enums\VehicleContractStatus;
use App\Models\VehicleContract;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/**
 * Un contrat propriétaire-véhicule — ex-`pages.admin.contracts.owner`.
 *
 * Les montants suivent `VehicleContractService::getStats()` : payé = paiements
 * `completed`, en net. `isEditable` reprend la règle du service — pas de modification
 * tant que le véhicule a un agent actif — pour que l'écran l'annonce avant la saisie.
 * `isDeletable` fait de même pour la suppression.
 *
 * ⚠️ Attend un contrat chargé par `ListVehicleContracts::query()` : `completed_paid`,
 * les trois compteurs d'historique et `vehicle.activeDriverContract`.
 */
final class AdminVehicleContractListItemData extends BaseData
{
    public function __construct(
        public string $id,
        #[TypeScriptType(VehicleContractStatus::class)]
        public string $status,
        public ?AdminVehicleContractPartyData $owner,
        public ?AdminVehicleContractVehicleData $vehicle,
        public ?int $contractMonths,
        public ?string $startDate,
        public ?string $endDate,
        public float $totalAmount,
        public float $totalPaid,
        public float $remaining,
        public float $surplus,
        public int $progressPercent,
        public ?float $unlimitedInternet,
        public ?float $spotifyPremium,
        public ?float $managerRemuneration,
        public ?string $notes,
        public bool $isEditable,
        public bool $isDeletable,
        public string $createdAt,
    ) {}

    public static function fromModel(VehicleContract $contract): self
    {
        $totalAmount = (float) $contract->total_amount;
        $totalPaid = (float) ($contract->completed_paid ?? 0);
        $balance = $totalAmount - $totalPaid;

        $hasHistory = $contract->driver_contracts_count > 0
            || $contract->payments_count > 0
            || $contract->pauses_count > 0;

        return new self(
            id: $contract->id,
            status: $contract->status,
            owner: $contract->owner
                ? AdminVehicleContractPartyData::fromUser($contract->owner->id, $contract->owner)
                : null,
            vehicle: $contract->vehicle ? AdminVehicleContractVehicleData::fromModel($contract->vehicle) : null,
            contractMonths: $contract->contract_months,
            startDate: $contract->start_date?->toDateString(),
            endDate: $contract->end_date?->toDateString(),
            totalAmount: $totalAmount,
            totalPaid: $totalPaid,
            remaining: max(0, $balance),
            surplus: $balance < 0 ? abs($balance) : 0,
            progressPercent: $totalAmount > 0 ? (int) min(100, round($totalPaid / $totalAmount * 100)) : 0,
            unlimitedInternet: $contract->unlimited_internet !== null ? (float) $contract->unlimited_internet : null,
            spotifyPremium: $contract->spotify_premium !== null ? (float) $contract->spotify_premium : null,
            managerRemuneration: $contract->manager_remuneration !== null ? (float) $contract->manager_remuneration : null,
            notes: $contract->notes,
            isEditable: $contract->vehicle?->activeDriverContract === null,
            isDeletable: $contract->status !== 'active' && ! $hasHistory,
            createdAt: $contract->created_at->toIso8601String(),
        );
    }
}
