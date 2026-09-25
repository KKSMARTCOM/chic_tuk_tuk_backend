<?php

namespace App\Domains\Workforce\Application\Data;

use App\Shared\Data\BaseData;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * Création d'un agent — ex-Admin\DriverController::store().
 *
 * Deux modes, `contract_mode` (`new`/`renewal`, l'équivalent du `_contract_mode` du
 * Blade — sans le préfixe, qui n'avait de sens que comme convention de champ caché
 * HTML) :
 *
 *  - `new` : véhicule et propriétaire sont FACULTATIFS — un agent peut être créé sans
 *    aucune affectation, à faire plus tard ;
 *  - `renewal` : reconduit un contrat déjà terminé sur un véhicule dont le contrat
 *    propriétaire court encore. Tout y est obligatoire : reconduire sans choisir quoi
 *    reconduire n'a pas de sens.
 */
final class CreateDriverData extends BaseData
{
    public function __construct(
        public string $name,
        public ?string $email,
        public string $phone,
        public string $password,
        public ?string $adresse,
        public string $licenseNumber,
        public ?string $agentCode,
        public ?string $agentId,
        #[LiteralTypeScriptType("'new' | 'renewal'")]
        public string $contractMode,
        // Mode `new`.
        public ?string $ownerId,
        public ?string $vehicleId,
        public ?int $contractMonths,
        public ?string $startDate,
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
        $mode = $context->payload['contract_mode'] ?? 'new';

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', Rule::unique('users', 'email')->where('profil', 'driver')],
            'phone' => ['required', 'string', Rule::unique('users', 'phone')->where('profil', 'driver')],
            'password' => ['required', 'string', 'min:8', 'regex:/[A-Z]/', 'regex:/[0-9]/', 'regex:/[@$!%*#?&]/'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'license_number' => ['required', 'string'],
            'agent_code' => ['nullable', 'string', 'max:255'],
            'agent_id' => ['nullable', 'string', 'max:255', 'unique:drivers,agent_id'],
            'contract_mode' => ['required', 'in:new,renewal'],
        ];

        if ($mode === 'renewal') {
            $rules['renewal_agent_code'] = ['nullable', 'string', 'max:255'];
            $rules['renewal_agent_id'] = ['nullable', 'string', 'max:255', 'unique:drivers,agent_id'];
            $rules['renewal_owner_id'] = ['required', 'exists:users,id'];
            $rules['renewal_vehicle_id'] = ['required', 'exists:vehicles,id'];
            $rules['renewal_contract_months'] = ['required', 'integer', 'min:1'];
            $rules['renewal_start_date'] = ['required', 'date'];
        } else {
            $rules['owner_id'] = ['nullable', 'exists:users,id'];
            $rules['vehicle_id'] = ['nullable', 'required_with:owner_id', 'exists:vehicles,id'];
            $rules['contract_months'] = ['nullable', 'integer', 'in:24,30,36'];
            $rules['start_date'] = ['nullable', 'date'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'name.required' => 'Le nom est requis.',
            'phone.required' => 'Le téléphone est requis.',
            'phone.unique' => 'Ce numéro de téléphone est déjà utilisé.',
            'email.unique' => 'Cet email est déjà utilisé.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
            'password.regex' => 'Le mot de passe doit contenir une majuscule, un chiffre et un caractère spécial.',
            'license_number.required' => 'La catégorie de permis est requise.',
            'agent_code.unique' => 'Ce code agent est déjà utilisé.',
            'agent_id.unique' => 'Cet ID agent est déjà utilisé.',
            'owner_id.exists' => 'Le propriétaire sélectionné est invalide.',
            'vehicle_id.exists' => 'Le véhicule sélectionné est invalide.',
            'vehicle_id.required_with' => 'Le véhicule est requis lorsque le propriétaire est sélectionné.',
            'renewal_owner_id.required' => 'Le propriétaire est requis.',
            'renewal_vehicle_id.required' => 'Le véhicule est requis.',
            'renewal_contract_months.required' => 'La durée du contrat est requise.',
            'renewal_contract_months.min' => 'La durée du contrat doit être d\'au moins 1 mois.',
            'renewal_start_date.required' => 'La date de début est requise.',
        ];
    }

    /** Charge utile attendue par `DriverService::createDriver()`, préfixe `_contract_mode` compris. */
    public function toServicePayload(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'password' => $this->password,
            'adresse' => $this->adresse,
            'license_number' => $this->licenseNumber,
            'agent_code' => $this->agentCode,
            'agent_id' => $this->agentId,
            '_contract_mode' => $this->contractMode,
            'owner_id' => $this->ownerId,
            'vehicle_id' => $this->vehicleId,
            'contract_months' => $this->contractMonths,
            'start_date' => $this->startDate,
            'renewal_agent_code' => $this->renewalAgentCode,
            'renewal_agent_id' => $this->renewalAgentId,
            'renewal_owner_id' => $this->renewalOwnerId,
            'renewal_vehicle_id' => $this->renewalVehicleId,
            'renewal_contract_months' => $this->renewalContractMonths,
            'renewal_start_date' => $this->renewalStartDate,
        ];
    }
}
