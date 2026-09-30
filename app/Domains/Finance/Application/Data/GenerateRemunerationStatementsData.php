<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;

/** POST /admin/remuneration-statements/generate — un mois, et au besoin un seul contrat. */
final class GenerateRemunerationStatementsData extends BaseData
{
    public function __construct(
        public string $month,
        public ?string $vehicleContractId = null,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'month' => ['required', 'date_format:Y-m'],
            'vehicle_contract_id' => ['nullable', 'uuid', 'exists:vehicle_contracts,id'],
        ];
    }
}
