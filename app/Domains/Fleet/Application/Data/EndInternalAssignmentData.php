<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;

/** POST /admin/internal-assignments/{assignment}/end */
final class EndInternalAssignmentData extends BaseData
{
    public function __construct(public string $endDate) {}

    public static function rules(): array
    {
        return ['end_date' => ['required', 'date']];
    }

    public static function messages(): array
    {
        return ['end_date.required' => 'La date de fin est obligatoire.'];
    }
}
