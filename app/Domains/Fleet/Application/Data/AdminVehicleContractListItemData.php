<?php

namespace App\Domains\Fleet\Application\Data;

use App\Domains\Fleet\Domain\Enums\VehicleContractStatus;
use App\Domains\Finance\Domain\ContractTargets;
use App\Models\RemunerationStatement;
use App\Models\VehicleContract;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/**
 * Un contrat propriétaire-véhicule — ex-`pages.admin.contracts.owner`.
 *
 * Les montants suivent l'ancien `VehicleContractService::getStats()` du Blade : payé = paiements
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
        /** Saisi à la création, corrigeable même avec un agent actif (2026-10-14). */
        public ?float $investedAmount,
        public ?string $notes,
        public bool $isEditable,
        public bool $isDeletable,
        /** Les deux cumuls atteints, contrat actif : « À solder » (spec 2026-10-12, §4.1). */
        public bool $readyToSettle,
        /** Les deux cumuls d'après la dernière fiche validée, en % de leur cible, plafonnés à 100 (spec 2026-10-12, §7.1). */
        public int $revenuePercent,
        public int $chargesPercent,
        public string $createdAt,
    ) {}

    public static function fromModel(VehicleContract $contract): self
    {
        $totalAmount = (float) $contract->total_amount;
        $totalPaid = (float) ($contract->completed_paid ?? 0);
        $balance = $totalAmount - $totalPaid;

        $hasHistory = $contract->driver_contracts_count > 0
            || $contract->payments_count > 0
            || $contract->pauses_count > 0
            || $contract->internal_assignments_count > 0;

        $cumulatives = self::cumulatives($contract);

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
            investedAmount: $contract->invested_amount !== null ? (float) $contract->invested_amount : null,
            notes: $contract->notes,
            isEditable: $contract->vehicle?->activeDriverContract === null,
            isDeletable: $contract->status !== 'active' && ! $hasHistory,
            readyToSettle: $contract->isReadyToSettle(),
            revenuePercent: self::percent($cumulatives['revenue'], ContractTargets::revenue($contract)),
            chargesPercent: self::percent($cumulatives['charges'], ContractTargets::charges($contract)),
            createdAt: $contract->created_at->toIso8601String(),
        );
    }

    /**
     * Les cumuls de la dernière fiche validée : lus de la sous-requête de la liste
     * (`ListVehicleContracts::query()`), sinon d'une requête — la fiche d'un seul contrat.
     *
     * @return array{revenue: float, charges: float}
     */
    private static function cumulatives(VehicleContract $contract): array
    {
        if (array_key_exists('last_cumulative_revenue', $contract->getAttributes())) {
            return ['revenue' => (float) $contract->last_cumulative_revenue, 'charges' => (float) $contract->last_cumulative_charges];
        }
        $figures = RemunerationStatement::query()->where('vehicle_contract_id', $contract->id)
            ->where('status', 'validated')->orderByDesc('month')->value('figures') ?? [];
        $figures = is_string($figures) ? json_decode($figures, true) : $figures;

        return ['revenue' => (float) ($figures['cumulative_revenue'] ?? 0), 'charges' => (float) ($figures['cumulative_charges'] ?? 0)];
    }

    private static function percent(float $cumulative, float $target): int
    {
        return $target > 0 ? (int) min(100, round($cumulative / $target * 100)) : 0;
    }
}
