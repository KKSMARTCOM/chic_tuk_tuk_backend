<?php

namespace App\Domains\Fleet\Application\Data;

use App\Domains\Fleet\Domain\Enums\VehicleContractStatus;
use App\Shared\Data\BaseData;
use Illuminate\Validation\Rule;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/**
 * PUT /admin/vehicle-contracts/{id} — la modale de modification du Blade.
 *
 * `vehicleId` vide garde le véhicule actuel. Pas de date de fin : la modale n'en avait
 * pas, et le service la laisse désormais intacte.
 */
final class UpdateVehicleContractData extends BaseData
{
    public function __construct(
        public int $contractMonths,
        public float $totalAmount,
        public string $startDate,
        #[TypeScriptType(VehicleContractStatus::class)]
        public string $status,
        public ?string $vehicleId = null,
        public ?float $unlimitedInternet = null,
        public ?float $spotifyPremium = null,
        public ?float $managerRemuneration = null,
        public ?string $notes = null,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'vehicle_id' => ['nullable', 'uuid', 'exists:vehicles,id'],
            'status' => ['required', Rule::in(VehicleContractStatus::values())],
            ...VehicleContractInputData::rules(),
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'vehicle_id.exists' => 'Le véhicule sélectionné est invalide.',
            'status.required' => 'Le statut du contrat est obligatoire.',
            'status.in' => 'Le statut du contrat doit être actif, soldé ou annulé.',
            ...VehicleContractInputData::messages(),
        ];
    }

    /** @return array<string, mixed> les clés de `VehicleContractService::update()` */
    public function toServicePayload(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'contract_months' => $this->contractMonths,
            'total_amount' => $this->totalAmount,
            'start_date' => $this->startDate,
            'status' => $this->status,
            'unlimited_internet' => $this->unlimitedInternet,
            'spotify_premium' => $this->spotifyPremium,
            'manager_remuneration' => $this->managerRemuneration,
            'notes' => $this->notes,
        ];
    }
}
