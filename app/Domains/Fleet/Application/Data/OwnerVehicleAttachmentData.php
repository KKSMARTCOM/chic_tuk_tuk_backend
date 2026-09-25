<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * Un véhicule à rattacher à un propriétaire, à sa création ou à son édition :
 * un véhicule `existing`, choisi parmi les disponibles, ou un véhicule `new`, créé
 * à la volée.
 */
final class OwnerVehicleAttachmentData extends BaseData
{
    public function __construct(
        #[LiteralTypeScriptType("'existing' | 'new'")]
        public string $mode,
        public ?string $vehicleId = null,
        public ?string $vehicleNumber = null,
        #[LiteralTypeScriptType("'moto' | 'tricycle' | 'car' | null")]
        public ?string $vehicleType = null,
        public ?string $notes = null,
        public ?VehicleContractInputData $contract = null,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(ValidationContext $context): array
    {
        $mode = $context->payload['mode'] ?? null;

        return [
            'mode' => ['required', 'in:existing,new'],
            'vehicle_id' => $mode === 'existing' ? ['required', 'exists:vehicles,id'] : ['nullable'],
            'vehicle_number' => $mode === 'new'
                ? ['required', 'string', 'max:255', 'unique:vehicles,vehicle_number']
                : ['nullable'],
            'vehicle_type' => $mode === 'new' ? ['required', 'in:moto,tricycle,car'] : ['nullable'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'vehicle_id.required' => 'Choisissez un véhicule.',
            'vehicle_id.exists' => 'Le véhicule sélectionné n\'existe pas.',
            'vehicle_number.required' => 'Le numéro du nouveau véhicule est obligatoire.',
            'vehicle_number.unique' => 'Ce numéro de véhicule est déjà utilisé.',
            'vehicle_type.required' => 'Le type du nouveau véhicule est obligatoire.',
        ];
    }
}
