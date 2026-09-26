<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;

/**
 * POST /admin/vehicle-contracts — le contrat d'un véhicule, créé depuis sa fiche.
 *
 * Mêmes champs que le contrat saisi depuis l'écran d'un propriétaire. La modale Blade
 * demandait une mensualité et une date de fin mais pas la durée : corrigé le 2026-09-26.
 * Le propriétaire est celui du véhicule.
 */
final class CreateVehicleContractData extends BaseData
{
    public function __construct(
        public string $vehicleId,
        public int $contractMonths,
        public float $totalAmount,
        public string $startDate,
        public ?float $unlimitedInternet = null,
        public ?float $spotifyPremium = null,
        public ?float $managerRemuneration = null,
        public ?string $notes = null,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'vehicle_id' => ['required', 'uuid', 'exists:vehicles,id'],
            ...VehicleContractInputData::rules(),
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'vehicle_id.required' => 'Le véhicule est obligatoire.',
            'vehicle_id.exists' => 'Le véhicule sélectionné est invalide.',
            ...VehicleContractInputData::messages(),
        ];
    }

    /** @return array<string, mixed> les clés de `VehicleContractService::create()` */
    public function toServicePayload(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'contract_months' => $this->contractMonths,
            'total_amount' => $this->totalAmount,
            'start_date' => $this->startDate,
            'unlimited_internet' => $this->unlimitedInternet,
            'spotify_premium' => $this->spotifyPremium,
            'manager_remuneration' => $this->managerRemuneration,
            'notes' => $this->notes,
        ];
    }
}
