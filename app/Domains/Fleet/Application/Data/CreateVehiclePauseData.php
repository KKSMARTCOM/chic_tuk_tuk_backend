<?php

namespace App\Domains\Fleet\Application\Data;

use App\Domains\Fleet\Domain\Enums\VehiclePauseReason;
use App\Shared\Data\BaseData;
use Illuminate\Validation\Rule;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/** Mettre un véhicule en pause — ex-Admin\VehicleController::addPause(). */
final class CreateVehiclePauseData extends BaseData
{
    public function __construct(
        public string $startDate,
        public ?string $endDate,
        #[TypeScriptType(VehiclePauseReason::class)]
        public string $reasonType,
        public ?string $reasonNotes = null,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'reason_type' => ['required', Rule::in(array_column(VehiclePauseReason::cases(), 'value'))],
            'reason_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'start_date.required' => 'La date de début est requise.',
            'end_date.after_or_equal' => 'La date de fin doit être après ou égale à la date de début.',
            'reason_type.required' => 'Le type de raison est requis.',
            'reason_type.in' => 'Le type de raison sélectionné est invalide.',
            'reason_notes.max' => 'Les notes ne doivent pas dépasser 1000 caractères.',
        ];
    }

    /** @return array<string, mixed> */
    public function toServicePayload(): array
    {
        return [
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'reason_type' => $this->reasonType,
            'reason_notes' => $this->reasonNotes,
        ];
    }
}
