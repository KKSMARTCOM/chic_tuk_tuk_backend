<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;

/**
 * Création d'un propriétaire — ex-Admin\OwnerController::store().
 *
 * ⚠️ Unicité de l'e-mail et du téléphone sur TOUS les comptes. Le Blade ne la vérifiait
 * que parmi les `profil=client` — un reliquat —, alors que la contrainte en base est
 * globale : un numéro d'agent passait la validation et la base refusait l'insertion.
 *
 * Comme au Blade, un véhicule rattaché à la création exige son contrat.
 */
final class CreateOwnerData extends BaseData
{
    public function __construct(
        public string $name,
        public ?string $email,
        public string $phone,
        public string $password,
        public ?string $adresse,
        public bool $isActive = true,
        public ?OwnerVehicleAttachmentData $vehicle = null,
        /** Autorise le transfert d'un véhicule qui appartient à un autre propriétaire. */
        public bool $confirmTransfer = false,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'unique:users,email'],
            'phone' => ['required', 'string', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8', 'regex:/[A-Z]/', 'regex:/[0-9]/', 'regex:/[@$!%*#?&]/'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'vehicle' => ['nullable', 'array'],
            'vehicle.contract' => ['required_with:vehicle'],
            'confirm_transfer' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'name.required' => 'Le nom est obligatoire.',
            'email.unique' => 'Cette adresse email est déjà utilisée.',
            'phone.required' => 'Le téléphone est obligatoire.',
            'phone.unique' => 'Ce numéro est déjà utilisé.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
            'password.regex' => 'Le mot de passe doit contenir au moins une majuscule, un chiffre et un caractère spécial (@$!%*#?&).',
            'vehicle.contract.required_with' => 'Le contrat est obligatoire quand un véhicule est rattaché.',
        ];
    }

    /**
     * Charge utile attendue par `OwnerService::create()`, aux clés du formulaire Blade.
     *
     * @return array<string, mixed>
     */
    public function toServicePayload(): array
    {
        $payload = [
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'password' => $this->password,
            'adresse' => $this->adresse,
            'is_active' => $this->isActive,
            'confirm_transfer' => $this->confirmTransfer,
        ];

        if ($this->vehicle === null) {
            return $payload;
        }

        if ($this->vehicle->mode === 'new') {
            $payload['new_vehicle_number'] = $this->vehicle->vehicleNumber;
            $payload['new_vehicle_type'] = $this->vehicle->vehicleType;
            $payload['new_vehicle_notes'] = $this->vehicle->notes;
        } else {
            $payload['vehicle_id'] = $this->vehicle->vehicleId;
        }

        return array_merge($payload, $this->vehicle->contract?->toServicePayload() ?? []);
    }
}
