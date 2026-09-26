<?php

namespace App\Domains\Workforce\Application\Data;

use App\Domains\Workforce\Domain\Enums\DriverContractEndReason;
use App\Shared\Data\BaseData;
use Illuminate\Validation\Rule;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/** POST /admin/driver-contracts/{id}/end — la modale « Terminer le contrat ». */
final class EndDriverContractData extends BaseData
{
    public function __construct(
        public string $endDate,
        #[LiteralTypeScriptType("'demission' | 'abandon' | 'fin_contrat' | 'autre'")]
        public string $endReason,
        public ?string $endNotes = null,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'end_date' => ['required', 'date'],
            'end_reason' => ['required', 'string', Rule::in(DriverContractEndReason::selectable())],
            'end_notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'end_date.required' => 'La date de fin est obligatoire.',
            'end_reason.required' => 'La raison est obligatoire.',
            'end_reason.in' => 'La raison choisie est invalide.',
        ];
    }

    /** @return array<string, mixed> les clés de `DriverContractService::end()` */
    public function toServicePayload(): array
    {
        return [
            'end_date' => $this->endDate,
            'end_reason' => $this->endReason,
            'end_notes' => $this->endNotes,
        ];
    }
}
