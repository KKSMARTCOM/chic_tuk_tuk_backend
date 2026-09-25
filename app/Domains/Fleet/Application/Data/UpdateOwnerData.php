<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\DataCollectionOf;

/**
 * Édition d'un propriétaire — ex-Admin\OwnerController::update().
 *
 * `addVehicle` remplace le couple `_add_vehicle_mode` + champs préfixés du Blade. À
 * l'édition, son contrat est FACULTATIF, comme au Blade — il l'est à la création.
 *
 * ⚠️ Unicité de l'e-mail et du téléphone sur TOUS les comptes, voir `CreateOwnerData`.
 */
final class UpdateOwnerData extends BaseData
{
    public function __construct(
        public string $name,
        public ?string $email,
        public string $phone,
        public ?string $adresse,
        public bool $isActive,
        /** @var array<int, OwnerVehicleEditData> */
        #[DataCollectionOf(OwnerVehicleEditData::class)]
        public array $vehicles = [],
        public ?OwnerVehicleAttachmentData $addVehicle = null,
        /** Autorise le transfert d'un véhicule qui appartient à un autre propriétaire. */
        public bool $confirmTransfer = false,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        $ownerId = request()->route('owner');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', Rule::unique('users', 'email')->ignore($ownerId)],
            'phone' => ['required', 'string', Rule::unique('users', 'phone')->ignore($ownerId)],
            'adresse' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'vehicles' => ['present', 'array'],
            'add_vehicle' => ['nullable', 'array'],
            'confirm_transfer' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'name.required' => 'Le nom est obligatoire.',
            'phone.required' => 'Le téléphone est obligatoire.',
            'phone.unique' => 'Ce numéro est déjà utilisé.',
            'email.unique' => 'Cet email est déjà utilisé.',
        ];
    }

    /**
     * Charge utile attendue par `OwnerService::update()`, aux clés du formulaire Blade.
     *
     * @return array<string, mixed>
     */
    public function toServicePayload(): array
    {
        $payload = [
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'adresse' => $this->adresse,
            'is_active' => $this->isActive,
            'confirm_transfer' => $this->confirmTransfer,
            'vehicles' => [],
            '_add_vehicle_mode' => $this->addVehicle?->mode,
        ];

        foreach ($this->vehicles as $vehicle) {
            $payload['vehicles'][$vehicle->id] = array_merge([
                'vehicle_number' => $vehicle->vehicleNumber,
                'vehicle_type' => $vehicle->vehicleType,
                'vehicle_notes' => $vehicle->notes,
            ], $vehicle->contract?->toServicePayload() ?? []);
        }

        $contract = $this->addVehicle?->contract?->toServicePayload() ?? [];

        if ($this->addVehicle?->mode === 'new') {
            $payload['new_vehicle_number'] = $this->addVehicle->vehicleNumber;
            $payload['new_vehicle_type'] = $this->addVehicle->vehicleType;
            $payload['new_vehicle_notes'] = $this->addVehicle->notes;
            $payload['new'] = $contract;
        } elseif ($this->addVehicle?->mode === 'existing') {
            $payload['vehicle_id'] = $this->addVehicle->vehicleId;
            $payload['existing_vehicle'] = $contract;
        }

        return $payload;
    }
}
