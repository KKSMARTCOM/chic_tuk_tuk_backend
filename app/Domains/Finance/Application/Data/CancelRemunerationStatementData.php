<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;

/** POST /admin/remuneration-statements/{id}/cancel — le motif est obligatoire (spec §5.5). */
final class CancelRemunerationStatementData extends BaseData
{
    public function __construct(
        public string $reason,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'reason.required' => 'Le motif de l\'annulation est obligatoire.',
        ];
    }
}
