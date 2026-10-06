<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Domains\Fleet\Application\Actions\EndVehiclePauseBeforeAgentStart;
use App\Domains\Workforce\Application\Data\UpdateDriverData;
use App\Domains\Workforce\Domain\VehicleAssignmentRules;
use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

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
    public function __construct(
        private readonly CreateRenewalContract $createRenewalContract,
        private readonly EndVehiclePauseBeforeAgentStart $endVehiclePause,
    ) {}

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
            return $this->updateDriver($driver->user_id, array_merge($payload, [
                '_owner_mode' => $data->ownerMode,
                '_has_active_contract' => $hasActiveContract,
            ]));
        } catch (\Exception $e) {
            throw new ApiException(422, 'DRIVER_UPDATE_FAILED', $e->getMessage());
        }
    }

    private function updateDriver(string $driverId, array $data): User
    {
        return DB::transaction(function () use ($driverId, $data) {

            $user = User::findOrFail($driverId);

            // 1. Mettre à jour les infos du User
            $user->update([
                'name' => $data['name'],
                'email' => $data['email'] ?? $user->email,
                'phone' => $data['phone'],
                'is_active' => $data['is_active'] ?? $user->is_active,
                'adresse' => $data['adresse'] ?? $user->adresse,
            ]);

            // 2. Mettre à jour le profil Driver
            if ($user->driver) {
                $user->driver->update([
                    'license_number' => $data['license_number'] ?? $user->driver->license_number,
                    'is_available' => $data['is_available'] ?? $user->driver->is_available,
                    'agent_code' => $data['agent_code'] ?? $data['renewal_agent_code'] ?? $user->driver->agent_code,
                    'agent_id' => $data['agent_id'] ?? $data['renewal_agent_id'] ?? $user->driver->agent_id,
                ]);
            }

            // 3. Si pas de contrat actif → créer un nouveau contrat
            if (! ($data['_has_active_contract'] ?? true)) {

                $mode = $data['_owner_mode'] ?? 'existing';

                if ($mode === 'renewal') {
                    // Réutilise exactement la même logique que pour la création
                    ($this->createRenewalContract)($user->driver, $data);
                } elseif ($mode === 'existing') {
                    $vehicle = Vehicle::findOrFail($data['vehicle_id']);

                    if ($vehicle->owner_id !== $data['owner_id']) {
                        throw new \Exception('Ce véhicule n\'appartient pas au propriétaire sélectionné.');
                    }

                    VehicleAssignmentRules::assertAssignable($vehicle);

                    // La pause du véhicule se ferme la veille de l'arrivée : oubliée ici
                    // jusqu'au 2026-10-06, elle restait ouverte et immobilisait l'agent.
                    ($this->endVehiclePause)($vehicle, $data['existing_start_date']);

                    DriverContract::create([
                        'driver_id' => $user->driver->id,
                        'vehicle_id' => $vehicle->id,
                        'vehicle_contract_id' => $vehicle->activeVehicleContract?->id,
                        'start_date' => $data['existing_start_date'],
                        'contract_months' => $data['existing_contract_months'],
                        'status' => 'active',
                    ]);
                } else {
                    // ── mode 'new' : code existant inchangé ──
                    $ownerRole = Role::firstOrCreate(
                        ['name' => 'proprietaire', 'guard_name' => 'web'],
                        ['label' => 'Propriétaire']
                    );

                    $owner = User::create([
                        'name' => $data['new_owner_name'],
                        'phone' => $data['new_owner_phone'],
                        'email' => $data['new_owner_email'] ?? null,
                        'password' => Hash::make($data['new_owner_password']),
                        'profil' => 'client',
                        'is_active' => true,
                    ]);
                    $owner->assignRole($ownerRole);

                    $vehicle = Vehicle::create([
                        'owner_id' => $owner->id,
                        'vehicle_number' => $data['new_vehicle_number'],
                        'vehicle_type' => $data['new_vehicle_type'] ?? 'tricycle',
                        'color' => $data['new_vehicle_color'] ?? null,
                        'is_active' => true,
                    ]);

                    if (! empty($data['contract_total_amount'])) {
                        VehicleContract::create([
                            'vehicle_id' => $vehicle->id,
                            'owner_id' => $owner->id,
                            'total_amount' => $data['contract_total_amount'],
                            'monthly_payment' => $data['contract_monthly_payment'] ?? 0,
                            'start_date' => $data['contract_start_date'] ?? now(),
                            'end_date' => $data['contract_end_date'] ?? null,
                            'status' => 'active',
                        ]);
                    }

                    DriverContract::create([
                        'driver_id' => $user->driver->id,
                        'vehicle_id' => $vehicle->id,
                        'vehicle_contract_id' => $vehicle->activeVehicleContract?->id,
                        'start_date' => $data['new_start_date'],
                        'contract_months' => $data['new_contract_months'],
                        'status' => 'active',
                    ]);
                }
            }

            return $user->load('driver');
        });
    }
}
