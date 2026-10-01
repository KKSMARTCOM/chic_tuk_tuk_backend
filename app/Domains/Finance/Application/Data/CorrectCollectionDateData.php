<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;

/** PATCH /admin/payments/{id}/collected-on */
final class CorrectCollectionDateData extends BaseData
{
    public function __construct(
        public string $collectedOn,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return ['collected_on' => ['required', 'date_format:Y-m-d']];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return ['collected_on.required' => 'La date d\'encaissement est obligatoire.'];
    }
}
