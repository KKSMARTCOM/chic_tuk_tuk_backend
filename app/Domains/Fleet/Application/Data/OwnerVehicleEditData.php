<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * Un véhicule que le propriétaire possède déjà, modifié depuis sa fiche.
 *
 * `OwnerService::update()` ignore un véhicule qui n'est pas à ce propriétaire, ou
 * qu'un agent conduit : la lecture seule de l'écran est aussi tenue côté serveur.
 * Sans `contract`, le contrat en cours reste tel quel.
 */
final class OwnerVehicleEditData extends BaseData
{
    public function __construct(
        public string $id,
        public string $vehicleNumber,
        #[LiteralTypeScriptType("'moto' | 'tricycle' | 'car'")]
        public string $vehicleType,
        public ?string $notes = null,
        public ?VehicleContractInputData $contract = null,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(ValidationContext $context): array
    {
        return [
            'id' => ['required', 'uuid'],
            // Le Blade ne vérifiait pas l'unicité ici : un doublon finissait en erreur SQL.
            'vehicle_number' => [
                'required', 'string', 'max:255',
                Rule::unique('vehicles', 'vehicle_number')->ignore($context->payload['id'] ?? null),
            ],
            'vehicle_type' => ['required', 'in:moto,tricycle,car'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'vehicle_number.required' => 'Le numéro du véhicule est obligatoire.',
            'vehicle_number.unique' => 'Ce numéro de véhicule est déjà utilisé.',
        ];
    }
}
