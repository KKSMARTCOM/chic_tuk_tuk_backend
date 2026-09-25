<?php

namespace App\Domains\Fleet\Application\Data;

use App\Models\VehiclePause;
use App\Shared\Data\BaseData;

/** Terminer une pause véhicule — ex-Admin\VehicleController::endPause(). */
final class EndVehiclePauseData extends BaseData
{
    public function __construct(
        public string $endDate,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        $pause = VehiclePause::find(request()->route('pause'));
        $rules = ['required', 'date'];

        if ($pause) {
            $rules[] = 'after_or_equal:'.$pause->start_date->toDateString();
        }

        return ['end_date' => $rules];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'end_date.required' => 'La date de fin est requise.',
            'end_date.after_or_equal' => 'La date de fin doit être après ou égale à la date de début.',
        ];
    }
}
