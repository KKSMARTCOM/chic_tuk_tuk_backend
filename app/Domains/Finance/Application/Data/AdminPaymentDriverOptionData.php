<?php

namespace App\Domains\Finance\Application\Data;

use App\Models\Driver;
use App\Shared\Data\BaseData;

/**
 * Un agent proposé au filtre de la liste, ou au formulaire de paiement.
 *
 * `contractMonths` et `vehicleNumber` rappellent le contrat actif, comme la ligne
 * « Contrat actif de N mois — Véhicule … » du formulaire Blade ; nuls hors contrat.
 */
final class AdminPaymentDriverOptionData extends BaseData
{
    public function __construct(
        public string $id,
        public ?string $name,
        public ?string $agentId,
        public ?int $contractMonths,
        public ?string $vehicleNumber,
    ) {}

    public static function fromModel(Driver $driver): self
    {
        return new self(
            id: $driver->id,
            name: $driver->user?->name,
            agentId: $driver->agent_id,
            contractMonths: $driver->activeDriverContract?->contract_months,
            vehicleNumber: $driver->activeDriverContract?->vehicle?->vehicle_number,
        );
    }
}
