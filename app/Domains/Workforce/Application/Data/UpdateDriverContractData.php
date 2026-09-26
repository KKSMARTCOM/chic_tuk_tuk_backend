<?php

namespace App\Domains\Workforce\Application\Data;

use App\Shared\Data\BaseData;

/**
 * PUT /admin/driver-contracts/{id} — date de début, durée, et véhicule au besoin.
 *
 * `vehicleId` vide garde le véhicule actuel. La durée n'est plus bornée à 24/30/36 : une
 * reconduction crée des contrats de la durée restante du contrat véhicule, que la liste
 * fermée du Blade aurait écrasée à l'enregistrement.
 */
final class UpdateDriverContractData extends BaseData
{
    public function __construct(
        public string $startDate,
        public int $contractMonths,
        public ?string $vehicleId = null,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'start_date' => ['required', 'date'],
            'contract_months' => ['required', 'integer', 'min:1'],
            'vehicle_id' => ['nullable', 'uuid', 'exists:vehicles,id'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'start_date.required' => 'La date de début est obligatoire.',
            'contract_months.required' => 'La durée du contrat est obligatoire.',
            'contract_months.min' => 'La durée du contrat doit être un nombre positif.',
            'vehicle_id.exists' => 'Le véhicule sélectionné est invalide.',
        ];
    }
}
