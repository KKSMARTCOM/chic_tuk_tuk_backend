<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;

/** POST /admin/driver-contracts/{id}/payments/preview — une période. */
final class ContractPaymentPeriodData extends BaseData
{
    public function __construct(
        public string $from,
        public string $to,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'from.required' => 'La date de début est obligatoire.',
            'to.required' => 'La date de fin est obligatoire.',
            'to.after_or_equal' => 'La date de fin doit suivre la date de début.',
        ];
    }
}
