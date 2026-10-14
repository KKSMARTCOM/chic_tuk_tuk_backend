<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;

/** PATCH /admin/vehicle-contracts/{id}/invested-amount — corriger le montant investi (2026-10-14). */
final class UpdateInvestedAmountData extends BaseData
{
    public function __construct(
        public float $investedAmount,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'invested_amount' => ['required', 'numeric', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'invested_amount.required' => 'Le montant investi est obligatoire.',
            'invested_amount.numeric' => 'Le montant investi est un nombre.',
            'invested_amount.min' => 'Le montant investi ne peut pas être négatif.',
        ];
    }
}
