<?php

namespace App\Domains\Workforce\Application\Data;

use App\Shared\Data\BaseData;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * Édition d'un agent — ex-Admin\DriverController::update().
 *
 * ⚠️ AUCUN champ de contrat n'est requis ICI, contrairement au Blade : ces champs ne
 * sont obligatoires QUE si l'agent n'a pas de contrat actif, une condition que seul le
 * serveur connaît (l'agent visé, pas le payload). `UpdateDriver` la vérifie lui-même
 * après avoir chargé l'agent, avec les mêmes règles que le Blade.
 *
 * ⚠️ Le mode `_owner_mode=new` du Blade (créer un propriétaire ET un véhicule à la
 * volée) est DU CODE MORT : aucun bouton de `edit.blade.php` ne l'atteint — seuls
 * `existing` et `renewal` existent dans l'interface. Non transposé.
 */
final class UpdateDriverData extends BaseData
{
    public function __construct(
        public string $name,
        public ?string $email,
        public string $phone,
        public ?bool $isActive,
        public ?string $adresse,
        public string $licenseNumber,
        public ?bool $isAvailable,
        public ?string $agentCode,
        public ?string $agentId,
        #[LiteralTypeScriptType("'existing' | 'renewal'")]
        public string $ownerMode,
        // Mode `existing`.
        public ?string $ownerId,
        public ?string $vehicleId,
        public ?int $existingContractMonths,
        public ?string $existingStartDate,
        // Mode `renewal`.
        public ?string $renewalAgentCode,
        public ?string $renewalAgentId,
        public ?string $renewalOwnerId,
        public ?string $renewalVehicleId,
        public ?int $renewalContractMonths,
        public ?string $renewalStartDate,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(ValidationContext $context): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email'],
            'phone' => ['required', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'license_number' => ['required', 'string'],
            'is_available' => ['nullable', 'boolean'],
            'agent_code' => ['nullable', 'string', 'max:255'],
            'agent_id' => ['nullable', 'string', 'max:255'],
            'owner_mode' => ['required', 'in:existing,renewal'],
            'owner_id' => ['nullable', 'exists:users,id'],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'existing_contract_months' => ['nullable', 'integer', 'in:24,30,36'],
            'existing_start_date' => ['nullable', 'date'],
            'renewal_agent_code' => ['nullable', 'string', 'max:255'],
            'renewal_agent_id' => ['nullable', 'string', 'max:255'],
            'renewal_owner_id' => ['nullable', 'exists:users,id'],
            'renewal_vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'renewal_contract_months' => ['nullable', 'integer', 'min:1'],
            'renewal_start_date' => ['nullable', 'date'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'name.required' => 'Le nom est requis.',
            'phone.required' => 'Le téléphone est requis.',
            'license_number.required' => 'La catégorie de permis est requise.',
        ];
    }

    /** Charge utile attendue par `DriverService::updateDriver()`, sans les clés `_*`. */
    public function toServicePayload(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'is_active' => $this->isActive,
            'adresse' => $this->adresse,
            'license_number' => $this->licenseNumber,
            'is_available' => $this->isAvailable,
            'agent_code' => $this->agentCode,
            'agent_id' => $this->agentId,
            'owner_id' => $this->ownerId,
            'vehicle_id' => $this->vehicleId,
            'existing_contract_months' => $this->existingContractMonths,
            'existing_start_date' => $this->existingStartDate,
            'renewal_agent_code' => $this->renewalAgentCode,
            'renewal_agent_id' => $this->renewalAgentId,
            'renewal_owner_id' => $this->renewalOwnerId,
            'renewal_vehicle_id' => $this->renewalVehicleId,
            'renewal_contract_months' => $this->renewalContractMonths,
            'renewal_start_date' => $this->renewalStartDate,
        ];
    }
}
