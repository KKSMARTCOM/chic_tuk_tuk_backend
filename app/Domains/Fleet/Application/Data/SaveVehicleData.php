<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;
use Illuminate\Validation\Rule;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * Création ou modification d'un véhicule — ex-Admin\VehicleController::store() et
 * update(). Le même formulaire au Blade, une même modale.
 *
 * Un véhicule naît sans propriétaire : on le rattache depuis l'écran d'un propriétaire.
 */
final class SaveVehicleData extends BaseData
{
    public function __construct(
        public string $vehicleNumber,
        #[LiteralTypeScriptType("'moto' | 'tricycle' | 'car'")]
        public string $vehicleType,
        public ?string $notes = null,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'vehicle_number' => [
                'required', 'string', 'max:255',
                Rule::unique('vehicles', 'vehicle_number')->ignore(request()->route('vehicle')),
            ],
            'vehicle_type' => ['required', 'in:moto,tricycle,car'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'vehicle_number.required' => 'Le numéro de véhicule est obligatoire.',
            'vehicle_number.unique' => 'Ce numéro de véhicule existe déjà.',
            'vehicle_type.required' => 'Le type de véhicule est obligatoire.',
            'vehicle_type.in' => 'Le type de véhicule sélectionné est invalide.',
            'notes.max' => 'Les notes ne doivent pas dépasser 500 caractères.',
        ];
    }

    /** @return array<string, mixed> */
    public function toServicePayload(): array
    {
        return [
            'vehicle_number' => $this->vehicleNumber,
            'vehicle_type' => $this->vehicleType,
            'notes' => $this->notes,
        ];
    }
}
