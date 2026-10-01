<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;

/**
 * POST /admin/driver-contracts/{id}/payments/generate — la période, les jours annulés
 * cochés, et les jours « à générer » vus dans l'aperçu : ceux qui ne le sont plus au
 * moment de la confirmation reviennent dans `skipped`.
 */
final class GenerateContractPaymentsData extends BaseData
{
    public function __construct(
        public string $from,
        public string $to,
        /** @var string[] */
        public array $regenerateCancelled = [],
        /** @var string[] */
        public array $expected = [],
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'regenerate_cancelled' => ['sometimes', 'array'],
            'regenerate_cancelled.*' => ['date_format:Y-m-d'],
            'expected' => ['sometimes', 'array'],
            'expected.*' => ['date_format:Y-m-d'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return ContractPaymentPeriodData::messages();
    }
}
