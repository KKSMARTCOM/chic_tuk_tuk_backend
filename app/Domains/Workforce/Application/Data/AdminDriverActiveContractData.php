<?php

namespace App\Domains\Workforce\Application\Data;

use App\Models\DriverContract;
use App\Models\Vehicle;
use App\Shared\Data\BaseData;

/** Le cadre « Contrat véhicule actif » du dossier agent — null si l'agent n'a aucun contrat actif. */
final class AdminDriverActiveContractData extends BaseData
{
    public function __construct(
        public string $vehicleId,
        public string $vehicleNumber,
        public ?string $vehicleType,
        public ?string $vehicleColor,
        public ?string $ownerName,
        public ?string $ownerPhone,
        public int $contractMonths,
        public string $startDate,
        public int $monthsElapsed,
        /** Le contrat reste modifiable tant qu'aucune pause ni paiement n'y est lié. */
        public bool $editable,
    ) {}

    public static function fromModel(DriverContract $contract, Vehicle $vehicle): self
    {
        return new self(
            vehicleId: $vehicle->id,
            vehicleNumber: $vehicle->vehicle_number,
            vehicleType: $vehicle->vehicle_type,
            vehicleColor: $vehicle->color,
            ownerName: $vehicle->owner?->name,
            ownerPhone: $vehicle->owner?->phone,
            contractMonths: (int) $contract->contract_months,
            startDate: $contract->start_date->format('Y-m-d'),
            monthsElapsed: (int) $contract->months_elapsed,
            editable: $contract->leaveRequests()->count() === 0 && $contract->payments()->count() === 0,
        );
    }
}
