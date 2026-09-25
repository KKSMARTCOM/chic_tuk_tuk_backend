<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Domains\Workforce\Application\Data\UpdateDriverData;
use App\Models\Driver;
use App\Models\User;
use App\Services\DriverService;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Modifier un agent — ex-Admin\DriverController::update().
 *
 * ⚠️ Porte deux vérifications que `UpdateDriverData::rules()` ne peut PAS exprimer,
 * faute de connaître l'agent visé avant que la route ne le charge :
 *
 *  1. l'unicité de l'email et du téléphone, qui doit s'EXCLURE elle-même (l'agent garde
 *     forcément son propre email) ;
 *  2. les champs de contrat ne sont requis que si l'agent n'a PAS de contrat actif —
 *     exactement la condition que porte `$hasActiveContract` dans le Blade.
 */
final class UpdateDriver
{
    public function __construct(private readonly DriverService $driverService) {}

    public function __invoke(Driver $driver, UpdateDriverData $data): User
    {
        $hasActiveContract = $driver->activeDriverContract !== null;
        $payload = $data->toServicePayload();

        $rules = [
            'email' => ['nullable', 'email', Rule::unique('users', 'email')->where('profil', 'driver')->ignore($driver->user_id)],
            'phone' => ['required', 'string', Rule::unique('users', 'phone')->where('profil', 'driver')->ignore($driver->user_id)],
        ];

        if (! $hasActiveContract) {
            $rules = array_merge($rules, $data->ownerMode === 'renewal'
                ? [
                    'renewal_owner_id' => ['required', 'exists:users,id'],
                    'renewal_vehicle_id' => ['required', 'exists:vehicles,id'],
                    'renewal_contract_months' => ['required', 'integer', 'min:1'],
                    'renewal_start_date' => ['required', 'date'],
                    'renewal_agent_id' => ['nullable', 'string', 'max:255', Rule::unique('drivers', 'agent_id')->ignore($driver->id)],
                ]
                : [
                    'owner_id' => ['required', 'exists:users,id'],
                    'vehicle_id' => ['required', 'exists:vehicles,id'],
                    'existing_contract_months' => ['required', 'integer', 'in:24,30,36'],
                    'existing_start_date' => ['required', 'date'],
                    // Absente du Blade, qui ne la vérifiait qu'en renewal : sans elle, deux
                    // agents pouvaient partager un ID, la colonne n'ayant pas d'index unique.
                    'agent_id' => ['nullable', 'string', 'max:255', Rule::unique('drivers', 'agent_id')->ignore($driver->id)],
                ]);
        }

        Validator::make($payload, $rules, [
            'email.unique' => 'Cet email est déjà utilisé.',
            'phone.required' => 'Le téléphone est requis.',
            'phone.unique' => 'Ce numéro de téléphone est déjà utilisé.',
            'owner_id.required' => 'Le propriétaire est requis.',
            'vehicle_id.required' => 'Le véhicule est requis.',
            'existing_contract_months.required' => 'La durée du contrat est requise.',
            'existing_start_date.required' => 'La date de début est requise.',
            'renewal_owner_id.required' => 'Le propriétaire est requis.',
            'renewal_vehicle_id.required' => 'Le véhicule est requis.',
            'renewal_contract_months.required' => 'La durée du contrat est requise.',
            'renewal_start_date.required' => 'La date de début est requise.',
            'agent_id.unique' => 'Cet ID agent est déjà utilisé.',
            'renewal_agent_id.unique' => 'Cet ID agent est déjà utilisé.',
        ])->validate();

        try {
            return $this->driverService->updateDriver($driver->user_id, array_merge($payload, [
                '_owner_mode' => $data->ownerMode,
                '_has_active_contract' => $hasActiveContract,
            ]));
        } catch (\Exception $e) {
            throw new ApiException(422, 'DRIVER_UPDATE_FAILED', $e->getMessage());
        }
    }
}
