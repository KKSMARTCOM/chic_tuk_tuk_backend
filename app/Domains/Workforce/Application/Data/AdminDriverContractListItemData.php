<?php

namespace App\Domains\Workforce\Application\Data;

use App\Domains\Fleet\Application\Data\AdminVehicleContractPartyData;
use App\Domains\Fleet\Application\Data\AdminVehicleContractVehicleData;
use App\Domains\Workforce\Domain\Enums\DriverContractEndReason;
use App\Domains\Workforce\Domain\Enums\DriverContractStatus;
use App\Domains\Workforce\Domain\LeaveBalance;
use App\Models\DriverContract;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/**
 * Un contrat agent — ex-`pages.admin.contracts.driver`.
 *
 * ⚠️ Les jours de pause viennent de `LeaveBalance`, la formule de l'écran des pauses.
 * Le Blade lisait les accesseurs du modèle, une seconde formule qui comptait une pause
 * en cours en jours calendaires et ignorait les demandes en attente : les deux écrans se
 * contredisaient (corrigé le 2026-09-26). `availableLeaveDays` peut être NÉGATIF — le
 * dépassement est permis, le négatif est un surplus.
 *
 * `driver.id` est l'identifiant de l'AGENT (`drivers.id`).
 *
 * ⚠️ Attend un contrat chargé par `ListDriverContracts::query()` : les compteurs
 * `leave_requests_count` et `payments_count`, `driver.user`, `vehicle`.
 */
final class AdminDriverContractListItemData extends BaseData
{
    public function __construct(
        public string $id,
        #[TypeScriptType(DriverContractStatus::class)]
        public string $status,
        public ?AdminVehicleContractPartyData $driver,
        public bool $driverIsActive,
        public ?AdminVehicleContractVehicleData $vehicle,
        public ?string $startDate,
        public ?string $endDate,
        public int $contractMonths,
        public int $monthsElapsed,
        public int $accruedLeaveDays,
        public int $usedLeaveDays,
        public int $availableLeaveDays,
        public int $remainingLeaveDays,
        #[TypeScriptType('?'.DriverContractEndReason::class)]
        public ?string $endReason,
        public ?string $endReasonLabel,
        public ?string $endNotes,
        public bool $isEditable,
        public bool $isDeletable,
        public string $createdAt,
    ) {}

    public static function fromModel(DriverContract $contract): self
    {
        $driver = $contract->driver;
        $user = $driver?->user;
        $balance = $driver ? LeaveBalance::pour($driver, $contract) : LeaveBalance::aucun();
        $hasHistory = $contract->leave_requests_count > 0 || $contract->payments_count > 0;

        return new self(
            id: $contract->id,
            status: $contract->status,
            driver: $user ? AdminVehicleContractPartyData::fromUser($driver->id, $user) : null,
            driverIsActive: (bool) $user?->is_active,
            vehicle: $contract->vehicle ? AdminVehicleContractVehicleData::fromModel($contract->vehicle) : null,
            startDate: $contract->start_date?->toDateString(),
            endDate: $contract->end_date?->toDateString(),
            contractMonths: (int) $contract->contract_months,
            monthsElapsed: (int) $contract->months_elapsed,
            accruedLeaveDays: $balance->accruedDays,
            usedLeaveDays: $balance->usedDays,
            availableLeaveDays: $balance->availableDays,
            remainingLeaveDays: $balance->remainingDays,
            endReason: $contract->end_reason,
            endReasonLabel: $contract->end_reason
                ? (DriverContractEndReason::tryFrom($contract->end_reason)?->label() ?? $contract->end_reason)
                : null,
            endNotes: $contract->end_notes,
            isEditable: ! $hasHistory,
            isDeletable: $contract->status !== 'active' && ! $hasHistory,
            createdAt: $contract->created_at->toIso8601String(),
        );
    }
}
