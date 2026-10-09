<?php

namespace App\Domains\Fleet\Application\Data;

use App\Models\InternalAssignment;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/** Une affectation interne, pour l'espace admin (spec 2026-10-09, §5.1). */
final class AdminInternalAssignmentData extends BaseData
{
    public function __construct(
        public string $id,
        public string $driverId,
        public ?string $driverName,
        public ?string $vehicleNumber,
        public string $startDate,
        public ?string $endDate,
        public ?string $notes,
        #[LiteralTypeScriptType('"manual" | "driver_contract" | null')]
        public ?string $endedReason,
    ) {}

    /** Attend `driver.user` et `vehicleContract.vehicle` chargés. */
    public static function fromModel(InternalAssignment $assignment): self
    {
        return new self(
            id: $assignment->id,
            driverId: $assignment->driver_id,
            driverName: $assignment->driver?->user?->name,
            vehicleNumber: $assignment->vehicleContract?->vehicle?->vehicle_number,
            startDate: $assignment->start_date->toDateString(),
            endDate: $assignment->end_date?->toDateString(),
            notes: $assignment->notes,
            endedReason: $assignment->ended_reason,
        );
    }
}
