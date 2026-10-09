<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;

/** POST /admin/vehicle-contracts/{contract}/internal-assignments */
final class AssignInternalDriverData extends BaseData
{
    public function __construct(
        public string $driverId,
        public string $startDate,
        public ?string $notes = null,
    ) {}

    public static function rules(): array
    {
        return [
            'driver_id' => ['required', 'uuid', 'exists:drivers,id'],
            'start_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public static function messages(): array
    {
        return [
            'driver_id.required' => 'Choisissez l\'agent.',
            'start_date.required' => 'La date de début est obligatoire.',
        ];
    }
}
