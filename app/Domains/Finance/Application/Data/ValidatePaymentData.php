<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;

/** POST /admin/payments/{id}/validate — la date d'encaissement, aujourd'hui par défaut. */
final class ValidatePaymentData extends BaseData
{
    public function __construct(
        public ?string $collectedOn = null,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return ['collected_on' => ['nullable', 'date_format:Y-m-d']];
    }
}
