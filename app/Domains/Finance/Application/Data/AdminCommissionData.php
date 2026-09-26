<?php

namespace App\Domains\Finance\Application\Data;

use App\Domains\Finance\Domain\Enums\CommissionStatus;
use App\Domains\Fleet\Application\Data\AdminVehicleContractPartyData;
use App\Models\Commission;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/**
 * Une commission — ligne de `pages.admin.commissions.index`, et sa fiche.
 *
 * `driver.id` est l'identifiant de l'AGENT (`drivers.id`).
 */
final class AdminCommissionData extends BaseData
{
    public function __construct(
        public string $id,
        #[TypeScriptType(CommissionStatus::class)]
        public string $status,
        public float $amount,
        public ?string $date,
        public ?AdminVehicleContractPartyData $driver,
        public ?AdminCommissionBookingData $booking,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(Commission $commission): self
    {
        $driver = $commission->driver;

        return new self(
            id: $commission->id,
            status: $commission->status,
            amount: (float) $commission->amount,
            date: $commission->date?->toDateString(),
            driver: $driver?->user ? AdminVehicleContractPartyData::fromUser($driver->id, $driver->user) : null,
            booking: $commission->booking ? AdminCommissionBookingData::fromModel($commission->booking) : null,
            createdAt: $commission->created_at->toIso8601String(),
            updatedAt: $commission->updated_at->toIso8601String(),
        );
    }
}
